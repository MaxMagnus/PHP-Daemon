 <?php
/**
 * Create and run worker processes.
 * Use message queues and shared memory to coordinate worker processes and return work product to the daemon.
 * Uses system v message queues because afaik there's no existing PHP implementation of posix  queues.
 *
 * At a high level, workers are implemented using a Mediator pattern. When a worker is created (by passing a Callable or
 * an instance of Core_IWorkerInterface to the Core_Daemon::worker() method) the Daemon creates a Mediator instance and
 * passes-in the worker.
 *
 * When worker methods are called the Daemon is actually interacting with the Mediator instance. Calls are serialized in
 * a very simple proprietary serialization format (to avoid any additional dependencies) and dispatched to worker processes.
 * The Mediator is responsible for keeping worker processes running, mediating calls and returns, and enforcing timeouts on jobs.
 *
 * The daemon does have the option of disintermediating work by calling methods directly on the worker object. If a worker
 * alias was Acme, a disintermediated call to doSomething() from the Daemon execute() method would look like:
 * @example $this->Acme->inline()->doSomething();   // Call doSomething() in-process (blocking)
 *
 * And if Acme was a Function worker it would work in a similar way:
 * @example $this->Acme->inline();
 *
 *
 * @todo A health-check feature that will listen for a Usr signal and dump vital stats (number of calls, average
 *       duration, average wait, etc) and most importantly make data-driven advice about the suggested memory allocation
 *       and number of workers. It's very hard for a novice to have any feeling for those things and they are vital to
 *       having a worker that runs flawlessly.
 *
 * @author Shane Harter
 */
abstract class Core_Worker_Mediator
{
    /**
     * The version is used in case SHM memory formats change in the future.
     */
    const VERSION = 2.0;

    /**
     * Message Types
     */
    const WORKER_CALL = 3;
    const WORKER_RUNNING = 2;
    const WORKER_RETURN = 1;

    /**
     * Call Statuses
     */
    const UNCALLED = 0;
    const CALLED = 1;
    const RUNNING = 2;
    const RETURNED = 3;
    const TIMEOUT = 10;

    /**
     * Forking Strategies
     */
    const LAZY = 1;
    const MIXED = 2;
    const AGGRESSIVE = 3;

    /**
     * Each SHM block has a header with needed metadata.
     */
    const HEADER_ADDRESS = 1;

    /**
     * The forking strategy of the Worker
     *
     * @example self::LAZY
     * Daemon Startup:      No processes are forked
     * Worker Method Call:  If existing process(es) are busy, fork another worker process for this call, up to the workers() limit.
     * In Lazy forking, processes are only forked as-needed
     *
     * @example self::MIXED
     * Daemon Startup:      No processes are forked
     * Worker Method Call:  Fork maximum number of worker processes (as set via workers())
     * In Mixed forking, nothing is forked until the first method call but all forks are done simultaneously.
     *
     * @example self::AGGRESSIVE
     * Daemon Startup:      All processes are forked up front
     * Worker Method Call:  Processes are forked as-needed to maintain the max number of available workers
     *
     * @var int
     * @todo improve the intelligence behind the strategy selection to vary strategy by idle time in the daemon event loop, not the duration of the loop itself.
     */
    protected $forking_strategy = self::MIXED;

    /**
     * @var Core_Daemon
     */
    protected $daemon;

    /**
     * Running worker processes
     * @var array
     */
    protected $processes = array();

    /**
     * Methods available on the $object
     * @var array
     */
    protected $methods = array();

    /**
     * All Calls
     * A periodic garbage collection routine unsets ->args, ->return, leaving just the lightweight call meta-data behind
     * @var array of stdClass objects
     */
    protected $calls = array();

    /**
     * Call Counter - Used to assign keys in the local and shm $calls array
     * Note: Start at 1 because the first key in shm memory is reserved for the header
     * @var int
     */
    protected $call_count = 1;

    /**
     * Array of Call ID's of calls currently running on one of the worker processes.
     * Calls are added when we receive a Running ack from a worker, and they're removed when the worker returns
     * or when the $timeout is reached.
     * @var array
     */
    protected $running_calls = array();

    /**
     * Has the shutdown signal been received?
     * @var bool
     */
    protected $shutdown = false;

    /**
     * What is the alias this worker is set to on the Daemon?
     * @var string
     */
    protected $alias = '';

    /**
     * A handle to the IPC message queue
     * @var Resource
     */
    protected $queue;

    /**
     * A handle to the IPC Shared Memory resource
     * This should be a `protected` property but in a few instances in this class closures are used in a way that
     * really makes a lot of sense and they need access. I think these issues will be fixed with the improvements
     * to $this lexical scoping in PHP5.4
     * @var Resource
     */
    public $shm;

    /**
     * @var Core_Worker_Adapter
     */
    public $adapter;

    /**
     * The number of allowed concurrent workers
     * @example Set the worker count using $this->workers();
     * @var int
     */
    protected $workers = 1;

    /**
     * How long, in seconds, can worker methods take before they should be killed?
     * Timeouts are an important tool in call processing guarantees: Workers that are killed or crash cannot notify the
     * daemon of the error. In these cases, the daemon only knows that the job was not acked as complete. In that way,
     * all errors are just timeouts. Your timeout handler will be called and your daemon will have the chance to retry
     * or otherwise handle the failure.
     *
     * Note: If you use your Timeout handler to retry a call, notice the $call->retries count that is kept for you. If your
     * call consistently leads to a fatal error in your worker processes, unlimited retries will result in continued worker
     * failure until the daemon reaches its error tolerance limit and tries to restart itself. Even then it's possible for the
     * queued call to persist until a manual intervention. By limiting retries the daemon can recover from a series of worker
     * fatal errors without affecting the application's stability.
     *
     * Note: There may be deviation in enforcement up to the length of your loop_interval. So if you set this ot "5" and
     * your loop interval is 2.5 second, workers may be allowed to run for up to 7.5 seconds before timing out. This
     * happens because timeouts and the on_return and on_timeout calls are all handled inside the run() loop just before
     * your execute() method is called.
     *
     * @example set a Timeout using $this->timeout();
     * @var float
     */
    protected $timeout = 60;

    /**
     * Callback that's called when a worker completes it's job.
     * @example set a Return Handler using $this->onReturn();
     * @var callable
     */
    protected $on_return;

    /**
     * Callback that's called when a worker timeout is reached. See phpdoc comments on the $timeout property
     * @example set a Timeout Handler using $this->onTimeout();
     * @var callable
     */
    protected  $on_timeout;

    /**
     * Is the current instance the Parent (daemon-side) mediator, or the Child (worker-side) mediator?
     * @var bool
     */
    public $is_parent = true;

    /**
     * How big, at any time, can the IPC shared memory allocation be.
     * Default is 5MB. May need to be increased if you are passing very large datasets as Arguments and Return values.
     * @example Allocate shared memory using $this->malloc();
     * @var float
     */
    protected $memory_allocation;

    /**
     * Under-allocated shared memory is perhaps the largest possible cause of Worker failures, so if the Mediator believes
     * the memory is under-allocated it will set this variable and write the warning to the event log
     * @var Boolean
     */
    protected $memory_allocation_warning = false;

    /**
     * The ID of this worker pool -- used to address shared IPC resources
     * @var int
     */
    protected $id;

    /**
     * We use the ftok function to deterministically create worker queue IDs. The function turns a filesystem path to a token.
     * Since the path of this file is shared among all workers, a hidden temp file is created in /tmp/phpdaemon.
     * This var holds the variable name so the file can be removed
     * @var string
     */
    protected $ftok;

    /**
     * Return a valid callback for the supplied $call
     * @abstract
     * @param $call
     */
    protected abstract function get_callback(stdClass $call);


    public function __construct($alias, Core_Daemon $daemon, Core_Worker_Adapter $adapter = Null) {
        $this->alias = $alias;
        $this->daemon = $daemon;
        $this->memory_allocation = 5 * 1024 * 1024;
        if ($adapter === Null) {
            $this->adapter = new Core_Worker_Adapter_Shm();
        }

        $interval = $this->daemon->loop_interval();
        switch(true) {
            case $interval > 2 || $interval === 0:
                $this->forking_strategy = self::LAZY;
                break;
            case $interval > 1:
                $this->forking_strategy = self::MIXED;
                break;
            default:
                $this->forking_strategy = self::AGGRESSIVE;
                break;
        }
    }

    public function __destruct() {
        // This method intentionally left blank
        // The Daemon destructor calls teardown() on each worker
    }


    public function check_environment(Array $errors = array()) {
        if (function_exists('posix_kill') == false)
            $errors[] = 'The POSIX Extension is Not Installed';

        return $errors;
    }

    public function setup() {

        if ($this->is_parent) {

            // This is slightly grizzly. We need a deterministic ID so we can re-attach shared memory and message queues
            // after a daemon restart. The ID has to be an int which rules out hashing. Collisions would result in a very pesky bug.
            // So we want to use the ftok() function, but that needs a unique file path & name. Since this mediator file could be shared
            // by multiple daemons, we're going to mash-up the daemon filename with the worker alias, and create an empty file in a hidden /tmp directory.
            @mkdir('/tmp/.phpdaemon');
            $this->ftok = '/tmp/.phpdaemon/' . str_replace("/", "_", $this->daemon->filename()) . '_' . $this->alias;
            if (!touch($this->ftok))
                $this->fatal_error("Unable to create Worker ID. Ftok failed. Could not write to /tmp directory");

            $this->id = ftok($this->ftok, substr($this->alias, 0, 1));

            if (!is_numeric($this->id))
                $this->fatal_error("Unable to create Worker ID. Ftok failed. Could not write to /tmp directory");

            if (!$this->daemon->recover_workers())
                $this->ipc_destroy();

            $this->fork();
            $this->daemon->on(Core_Daemon::ON_RUN, array($this, 'run'));
            $this->ipc_create();
            $this->shm_init();

        } else {
            $this->calls = $this->processes = $this->running_calls = array();
            $this->ipc_create();
            $this->daemon->on(Core_Daemon::ON_SIGNAL, array($this, 'signal'));
            $this->log('Worker Process Started');
        }

        if (!is_resource($this->queue))
            throw new Exception(__METHOD__ . " Failed. Could not attach message queue id {$this->id}");

        if (!is_resource($this->shm))
            throw new Exception(__METHOD__ . " Failed. Could not address shared memory block {$this->id}");
    }

    /**
     * Called in the Daemon (parent) process during shutdown/restart to shutdown any worker processes.
     * Will attempt a graceful shutdown first and kill -9 only if the worker processes seem to be hanging.
     * @return mixed
     */
    public function teardown() {
        static $state = array();

        if (!$this->is_parent)
            return;

        if ($this->timeout > 0)
            $timeout = $this->timeout;
        else
            $timeout = 30;

        foreach(array_keys($this->processes) as $pid) {
            if (!isset($state[$pid])) {
                posix_kill($pid, SIGTERM);
                $state[$pid] = time();
                continue;
            }

            if (isset($state[$pid]) && ($state[$pid] + $timeout) < time()) {
                $this->log("Worker '{$pid}' Time Out: Killing Process.");
                posix_kill($pid, SIGKILL);
                unset($state[$pid]);
            }
        }

        // If there are no pending messages, release all shared resources.
        // If there are, then we want to preserve them so we can allow for daemon restarts without losing the call buffer
        if (count($this->processes) == 0) {
            $stat = $this->ipc_status();
            if ($stat['messages'] > 0) {
                return;
            }

            @unlink($this->ftok);
            $this->ipc_destroy();
        }
    }

    /**
     * Connect to (and create if necessary) Shared Memory and Message Queue resources
     * @return void
     */
    protected function ipc_create() {
        $this->shm      = shm_attach($this->id, $this->memory_allocation, 0666);
        $this->queue    = msg_get_queue($this->id, 0666);
    }

    /**
     * Remove and Reset any data in shared resources left over from previous instances of the Daemon.
     * In normal operation, this happens every time you restart the daemon.
     * To preserve the data and pick up where you left off, you can start a daemon with the --recoverworkers flag.
     * Note: Doing so can sometimes cause problems if the cause of the daemon restart was a broken/flawed call.
     * @param bool $mq   Destroy the message queue?
     * @param bool $shm  Destroy the shared memory?
     * @return void
     */
    protected function ipc_destroy($mq = true, $shm = true) {
        if (($mq && !is_resource($this->queue)) || ($shm && !is_resource($this->shm)))
            $this->ipc_create();

        if ($mq) {
            @msg_remove_queue($this->queue);
            $this->queue = null;
        }

        if ($shm) {
            @shm_remove($this->shm);
            @shm_detach($this->shm);
            $this->shm = null;
        }
    }

    /**
     * Get the status of IPC message queue and shared memory resources
     * @return array    Tuple of 'messages','memory_allocation'
     */
    protected function ipc_status() {

        $tuple = array(
            'messages' => null,
            'memory_allocation' => null,
        );

        $stat = @msg_stat_queue($this->queue);
        if (is_array($stat))
            $tuple['messages'] = $stat['msg_qnum'];

        $header = @shm_get_var($this->shm, 1);
        if (is_array($header))
            $tuple['memory_allocation'] = $header['memory_allocation'];

        return $tuple;
    }

    /**
     * Handle IPC Errors
     * @param $error_code
     * @param int $try    Inform ipc_error of repeated failures of the same $error_code
     * @return boolean  Returns true if the operation should be retried.
     */
    protected function ipc_error($error_code, $try=1) {

        $that = $this;
        $is_parent = $this->is_parent;

        // Count errors and compare them against thresholds.
        // Different thresholds for parent & children
        $counter = function($type) use($that, $is_parent) {
            static $error_thresholds = array(
                'identifier' => array(100, 10), // Identifier related errors: The underlying data structures are fine, but we need to re-create a resource handle
                'corruption' => array(10,  50), // Corruption related errors: The underlying data structures are corrupt (or possibly just OOM)
                'catchall'   => array(10,  50),
            );

            static $error_counts = array(
                'identifier' => 0,
                'corruption' => 0,
                'catchall'   => 0,
            );

            $error_counts[$type]++;
            if ($error_counts[$type] > $error_thresholds[$type][(int)$is_parent])
                $that->fatal_error("IPC '$type' Error Threshold Reached");
        };

        // Most of the error handling strategy is simply: Sleep for a moment and try again.
        // We use a simple back-off strategy: starting with 2 seconds, it would increase to 8, then 16, etc
        $backoff = function($delay) use ($try) {
            return $delay * pow(2, min($try, 1));
        };

        switch($error_code) {
            case 0:             // Success
            case 4:             // System Interrupt
            case MSG_ENOMSG:    // No message of desired type
            case MSG_EAGAIN:    // Temporary Problem, Try Again

                // Ignored Errors
                usleep($backoff(20000));
                return true;
                break;

            case 22:
                // Invalid Argument
                // Probably because the queue was removed in another process.

            case 43:
                // Identifier Removed
                // A message queue was re-created at this address but the resource identifier we have needs to be re-created
                if ($this->is_parent)
                    usleep($backoff(20000));
                else
                    sleep($backoff(2));

                $this->ipc_create();
                return true;
                break;

            case null:
                // Almost certainly an issue with shared memory
                $this->log("Shared Memory I/O Error at Address {$this->id}.");
                $counter('corruption');

                // If this is a worker, all we can do is try to re-attach the shared memory.
                // Any corruption or OOM errors will be handled by the parent exclusively.
                if (!$this->is_parent) {
                    sleep($backoff(3));
                    $this->ipc_create();
                    return true;
                }

                // If this is the parent, do some diagnostic checks and attempt correction.
                usleep($backoff(20000));

                $test = function() use($that) {
                    $arr = array_fill(0, mt_rand(10, 100), mt_rand(1000, 1000 * 1000));
                    $key = mt_rand(1000 * 1000, 2000 * 1000);
                    @shm_put_var($that->shm, $key, $arr);
                    usleep(5000);
                    return @shm_get_var($that->shm, $key) == $arr;
                };

                // Test writing to shared memory using an array that should come to a few kilobytes.
                for($i=0; $i<2; $i++) {
                    if ($test())
                        return true;

                    // Re-attach the shared memory and try the diagnostic again
                    $this->ipc_create();
                }

                // Attempt to re-connect the shared memory
                // See if we can read what's in shared memory and re-write it later
                $items_to_copy = array();
                $items_to_call = array();
                for ($i=0; $i<$this->call_count; $i++) {
                    $call = @shm_get_var($this->shm, $i);
                    if (!is_object($call))
                        continue;

                    if (!isset($this->calls[$i]))
                        continue;

                    if ($this->calls[$i]->status == self::TIMEOUT)
                        continue;

                    if ($this->calls[$i]->status == self::UNCALLED) {
                        $items_to_call[$i] = $call;
                        continue;
                    }

                    $items_to_copy[$i] = $call;
                }

                for($i=0; $i<2; $i++) {
                    $this->ipc_destroy(false, true);
                    $this->ipc_create();

                    if (!empty($items_to_copy))
                        foreach($items_to_copy as $key => $value)
                            @shm_put_var($this->shm, $key, $value);

                    if (!$test()) {
                        if (empty($items_to_copy)) {
                            $this->fatal_error("Shared Memory Failure: Unable to proceed.");
                        } else {
                            $this->log('Purging items from shared memory: ' . implode(', ', array_keys($items_to_copy)));
                            unset($items_to_copy);
                        }
                    }
                }

                foreach($items_to_call as $call) {
                    $this->retry($call);
                }

                return true;

            default:
                if ($error_code)
                    $this->log("Message Queue Error {$error_code}: " . posix_strerror($error_code));

                if ($this->is_parent)
                    usleep($backoff(20000));
                else
                    sleep($backoff(3));

                $counter('catchall');
                $this->ipc_create();
                return false;
        }
    }

    /**
     * Write and Verify the SHM header
     * @return void
     * @throws Exception
     */
    private function shm_init() {

        // Write a header to the shared memory block
        if (!shm_has_var($this->shm, self::HEADER_ADDRESS)) {
            $header = array(
                'version' => self::VERSION,
                'memory_allocation' => $this->memory_allocation,
            );

            if (!shm_put_var($this->shm, self::HEADER_ADDRESS, $header))
                throw new Exception(__METHOD__ . " Failed. Could Not Read Header. If this problem persists, try running the daemon with the --resetworkers option.");
        }

        // Check memory allocation and warn the user if their malloc() is not actually applicable
        $header = shm_get_var($this->shm, self::HEADER_ADDRESS);
        if ($header['memory_allocation'] <> $this->memory_allocation)
            $this->log('Warning: Seems you\'ve using --recoverworkers after making a change to the worker malloc memory limit. To apply this change you will have to restart the daemon without the --recoverworkers option.' .
                PHP_EOL . 'The existing memory_limit is ' . $header['memory_allocation'] . ' bytes.');

        // If we're trying to recover previous messages/shm, figure out where we left-off so we can avoid colliding our call ID's
        if ($this->daemon->recover_workers()) {
            $max_id = $this->call_count;
            for ($i=0; $i<100000; $i++) {
                if(shm_has_var($this->shm, $i))
                    $max_id = $i;
            }
            $this->log("Starting Job Numbering at $max_id.");
        }
    }

    /**
     * Fork an appropriate number of daemon processes. Looks at the daemon loop_interval to determine the optimal
     * forking strategy: If the loop is very tight, we will do all the forking up-front. For longer intervals, we will
     * fork as-needed. In the middle we will avoid forking until the first call, then do all the forks in one go.
     * @return mixed
     */
    protected function fork() {
        $processes = count($this->processes);
        if ($this->workers <= $processes)
            return;

        switch ($this->forking_strategy) {
            case self::LAZY:
                $stat = $this->ipc_status();
                if ($processes > count($this->running_calls) || count($this->calls) == 0 && $stat['messages'] == 0)
                    $forks = 0;
                else
                    $forks = 1;
                break;
            case self::MIXED:
            case self::AGGRESSIVE:
            default:
                $forks = $this->workers - $processes;
                break;
        }

        $errors = array();
        for ($i=0; $i<$forks; $i++) {

            $pid = $this->daemon->fork(array($this, 'start'), array(), true, $this->alias);
            if ($pid) {
                $this->processes[$pid] = microtime(true);
                continue;
            }

            // If the forking failed, we can retry a few times and then fatal-error
            // The most common reason this could happen is the PID table gets full (zombie processes left behind?)
            // or the machine runs out of memory.
            if (!isset($errors[$i])) {
                $errors[$i] = 0;
            }

            if ($errors[$i]++ < 3) {
                $i--;
                continue;
            }

            $this->fatal_error("Could Not Fork: See PHP error log for an error code and more information.");
        }
    }

    /**
     * Called in the Daemon to inform a worker one of it's forked processes has ed
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function reap($pid, $status) {
        static $failures = 0;
        static $last_failure = null;

        // Keep track of processes that fail within the first 30 seconds of being forked.
        if (isset($this->processes[$pid]) && time() - $this->processes[$pid] < 30) {
            $failures++;
            $last_failure = time();
        }

        if ($failures == 5) {
            $this->fatal_error("Unsuccessful Fork: Recently forked processes are continuously failing. See error log for additional details.");
        }

        // If there hasn't been a failure in 90 seconds, reset the counter.
        // The counter only exists to prevent an endless fork loop due to child processes fatal-erroring right after a successful fork.
        // Other types of errors will be handled elsewhere
        if ($failures && time() - $last_failure > 90) {
            $failures = 0;
            $last_failure = null;
        }
        unset($this->processes[$pid]);
    }

    /**
     * Called in the parent process, once per each iteration in the daemons run() loop. Checks messages queues for information from worker
     * processes, and enforces timeouts when applicable.
     * Note: Called only in the parent (daemon) process
     * @return void
     */
    public function run() {

        if (empty($this->calls))
            return;

        try {

            // If there are any callbacks registered (onReturn, onTimeout, etc), we will pass
            // the $call struct to them and this $logger closure
            $that = $this;
            $logger = function($message) use($that) {
                $that->log($message);
            };

            while(true) {
                $message_type = $message = $message_error = null;
                if (msg_receive($this->queue, self::WORKER_RUNNING, $message_type, $this->memory_allocation, $message, true, MSG_IPC_NOWAIT, $message_error)) {
                    $call_id = $this->message_decode($message);
                    $this->running_calls[$call_id] = true;
                    $this->log('Job ' . $call_id . ' Is Running');
                    continue;
                }

                $this->ipc_error($message_error);
                break;
            }

            while(true) {
                $message_type = $message = $message_error = null;
                if (msg_receive($this->queue, self::WORKER_RETURN, $message_type, $this->memory_allocation, $message, true, MSG_IPC_NOWAIT, $message_error)) {
                    $call_id = $this->message_decode($message);
                    $call = $this->calls[$call_id];

                    unset($this->running_calls[$call_id]);

                    $on_return = $this->on_return;
                    if (is_callable($on_return))
                        call_user_func($on_return, $call, $logger);
                    else
                        $this->log('No onReturn Callback Available');

                    if (!$this->memory_allocation_warning && $call->size > ($this->memory_allocation / 50)) {
                        $this->memory_allocation_warning = true;
                        $suggested_size = $call->size * 60;
                        $this->log("WARNING: The memory allocated to this worker is too low and may lead to failures and fatal out-of-shared-memory errors.\n".
                                   "         Based on this job, the memory allocation should be no less than {$suggested_size} bytes. Current allocation: {$this->memory_allocation} bytes.");
                    }

                    $this->log('Job ' . $call_id . ' Is Complete');
                    continue;
                }

                $this->ipc_error($message_error);
                break;
            }

            // Enforce Timeouts
            // Timeouts will either be simply that the worker is taking longer than expected to return the call,
            // or the worker actually fatal-errored and killed itself.
            if ($this->timeout > 0) {
                $now = microtime(true);
                foreach(array_keys($this->running_calls) as $call_id) {
                    $call = $this->calls[$call_id];
                    if (isset($call->time[self::RUNNING]) && $now > ($call->time[self::RUNNING] + $this->timeout)) {
                        $this->log("Enforcing Timeout on Call $call_id in pid " . $call->pid);
                        @posix_kill($call->pid, SIGKILL);
                        unset($this->running_calls[$call_id], $this->processes[$call->pid]);
                        $call->status = self::TIMEOUT;

                        $on_timeout = $this->on_timeout;
                        if (is_callable($on_timeout))
                            call_user_func($on_timeout, $call, $logger);

                    }
                }
            }

            // If we've killed all our processes -- either timeouts or maybe they fatal-errored -- and we have pending calls
            // in the queue, create process(es) to run them.
            if (count($this->processes) == 0) {
                $stat = $this->ipc_status();
                if ($stat['messages'] > 0) {
                    $this->fork();
                }
            }

        } catch (Exception $e) {
            $this->log(__METHOD__ . ' Failed: ' . $e->getMessage(), true);
        }
    }

    /**
     * Starts the event loop in the Forked process that will listen for messages
     * Note: Runs only in the child (forked) process
     * @return void
     */
    public function start() {
        $count = 0;
        $pid = getmypid();

        while(!$this->is_parent && !$this->shutdown) {
            $message_type = $message = $message_error = null;
            if (msg_receive($this->queue, self::WORKER_CALL, $message_type, $this->memory_allocation, $message, true, 0, $message_error)) {
                try {
                    // Auto-Kill each worker after they process 25 jobs AND live at least 5 minutes
                    $this->shutdown = (++$count > 25 && $this->daemon->runtime() > (60 * 5));

                    $call_id = $this->message_decode($message);
                    $call = $this->calls[$call_id];

                    $call->pid = $pid;
                    $call->status = self::RUNNING;
                    $call->time[self::RUNNING] = microtime(true);
                    if (!$this->message_encode($call_id)) {
                        $this->log("Call {$call_id} Could Not Ack Running.");
                    }

                    $call->return = call_user_func_array($this->get_callback($call), $call->args);
                    $call->status = self::RETURNED;
                    $call->size   = strlen(print_r($call, true));
                    $call->time[self::RETURNED] = microtime(true);
                    if (!$this->message_encode($call_id)) {
                        $this->log("Call {$call_id} Could Not Ack Complete.");
                    }
                }
                catch (Exception $e) {
                    $this->log($e->getMessage(), true);
                }

                // Give the CPU a break - Sleep for 1/50 a second.
                usleep(50000);
                continue;
            }
            $this->ipc_error($message_error);
        }
    }

    /**
     * Send messages for the given $call_id to the right queue based on that call's state. Writes call data
     * to shared memory at the address specified in the message.
     * @param $call_id
     * @return bool
     */
    protected function message_encode($call_id) {

        $call = $this->calls[$call_id];

        $queue_lookup = array(
            self::UNCALLED  => self::WORKER_CALL,
            self::RUNNING   => self::WORKER_RUNNING,
            self::RETURNED  => self::WORKER_RETURN
        );

        $that = $this;
        switch($call->status) {
            case self::UNCALLED:
            case self::RETURNED:
                $encoder = function($call) use ($that) {
                    shm_put_var($that->shm, $call->id, $call);
                    return shm_has_var($that->shm, $call->id);
                };
                break;

            default:
                $encoder = function($call) {
                    return true;
                };
        }

        $error_code = null;
        if ($encoder($call)) {

            $message = array (
                'call_id'   => $call->id,
                'status'    => $call->status,
                'microtime' => $call->time[$call->status],
                'pid'       => $this->daemon->pid(),
            );

            if (msg_send($this->queue, $queue_lookup[$call->status], $message, true, false, $error_code)) {
                return true;
            }
        }

        $call->errors++;
        if ($this->ipc_error($error_code, $call->errors) && $call->errors < 3) {
            $this->log("Message Encode Failed for call_id {$call_id}: Retrying.");
            return $this->message_encode($call_id);
        }

        return false;
    }

    /**
     * Decode the supplied-message. Pulls in data from the shared memory address referenced in the message.
     * @param array $message
     * @return mixed
     * @throws Exception
     */
    protected function message_decode(Array $message) {

        // Periodically garbage-collect the local and shm $calls array
        if (mt_rand(1, 20) == 10)
            foreach ($this->calls as $item_id => $item)
                if (!$item->gc && in_array($item->status, array(self::TIMEOUT, self::RETURNED))) {
                    unset($this->calls[$item_id]->args, $this->calls[$item_id]->return);
                    $this->calls[$item_id]->gc = true;
                    if (shm_has_var($this->shm, $item_id))
                        shm_remove_var($this->shm, $item_id);
                }

        $that = $this;
        switch($message['status']) {
            case self::UNCALLED:
                $decoder = function($message) use($that) {
                    return shm_get_var($that->shm, $message['call_id']);
                };
                break;

            case self::RETURNED:
                $decoder = function($message) use($that) {
                    $call = shm_get_var($that->shm, $message['call_id']);
                    if ($call && $call->status == $message['status'])
                        shm_remove_var($that->shm, $message['call_id']);

                    return $call;
                };
                break;

            default:
                $decoder = function($message) use($that) {
                    $call = $that->_call($message['call_id']);
                    $call->status               = $message['status'];
                    $call->time[$call->status]  = $message['microtime'];
                    $call->pid                  = $message['pid'];
                    return $call;
                };
        }

        // Now get on with decoding the $message
        $tries = 1;
        do {
            $call = $decoder($message);
        } while(empty($call) && $this->ipc_error(null, $tries) && $tries++ < 3);

        if (!is_object($call))
            throw new Exception(__METHOD__ . " Failed. Could Not Decode Message: " . print_r($message, true));

        $this->calls[$call->id] = $call;
        return $call->id;
    }

    /**
     * Mediate all calls to methods on the contained $object and pass them to instances of $object running in the background.
     * @param stdClass $call
     * @return A unique identifier for the call (unique to this execution only. After a restart the worker re-uses call IDs) OR false on error.
     *         Can be passed to the status() method for call status
     */
    protected function call(stdClass $call) {

        try {
            $this->calls[$call->id] = $call;
            if ($this->message_encode($call->id)) {
                $call->status = self::CALLED;
                $call->time[self::CALLED] = microtime(true);
                $this->fork();
                return $call->id;
            }
        } catch (Exception $e) {
            $this->log('Call Failed: ' . $e->getMessage(), true);
        }

        // The call failed -- args could be big so trim it back proactively, leaving
        // the call metadata the same way the GC process works
        $call->args = null;
        return false;
    }

    /**
     * Get the worker ID
     * @return int
     */
    public function id() {
        return $this->id;
    }

    /**
     * Satisfy the debugging interface in case there are user-created prompt() calls in their workers
     */
    public function prompt($prompt, $args = null, Closure $on_interrupt = null) {
        return true;
    }

    /**
     * Hack to work around deficient $this lexical scoping in PHP5.3 closures. Gives closures used in various
     * methods herein access to the $calls array. Hopefully can get rid of this when we move to require PHP5.4
     * @param integer $call_id
     * @return stdClass
     */
    public function _call($call_id) {
        if (isset($this->calls[$call_id]))
            return $this->calls[$call_id];

        return null;
    }

    /**
     * Intercept method calls on worker objects and pass them to the worker processes
     * @param $method
     * @param $args
     * @return bool
     * @throws Exception
     */
    public function __call($method, $args) {

        if (!in_array($method, $this->methods))
            throw new Exception(__METHOD__ . " Failed. Method `{$method}` is not callable.");

        $this->call_count++;
        $call = new stdClass();
        $call->method        = $method;
        $call->args          = $args;
        $call->status        = self::UNCALLED;
        $call->time          = array(microtime(true));
        $call->pid           = null;
        $call->id            = $this->call_count;
        $call->retries       = 0;
        $call->errors        = 0;
        $call->size          = null;
        $call->gc            = false;

        return $this->call($call);
    }

    /**
     * If your worker object implements an execute() method, it can be called in the daemon using $this->MyAlias()
     * @return bool
     */
    public function __invoke() {
        return $this->__call('execute', func_get_args());
    }

    /**
     * Attached to the Daemon's ON_SIGNAL event
     * @param $signal
     */
    public function signal($signal) {
        switch ($signal)
        {
            case SIGHUP:
                $this->log("Restarting Worker Process...");

            case SIGINT:
            case SIGTERM:
                $this->shutdown = true;
                break;
        }
    }





    /**
     * Write do the Daemon's event log
     *
     * Part of the Worker API - Use from your workers to log events to the Daemon error log
     *
     * @param $message
     * @param bool $is_error
     * @return void
     */
    public function log($message, $is_error = false) {
        $this->daemon->log("$message", $is_error, $this->alias);
    }

    /**
     * Log a fatal error and restart the worker process
     *
     * Part of the Worker API - Use from your worker to log a fatal error message and restart the current process.
     *
     * @param $message
     * @return void
     */
    public function fatal_error($message) {
        $this->daemon->fatal_error("$message\nFatal Error: Worker process will restart", $this->alias);
    }

    /**
     * Access daemon properties from within your workers
     *
     * Part of the Worker API - Use from your worker to access data set on your Daemon class
     *
     * @example [inside a worker class] $this->mediator->daemon('dbconn');
     * @example [inside a worker class] $ini = $this->mediator->daemon('ini'); $ini['database']['password']
     * @param $property
     * @return mixed
     */
    public function daemon($property) {
        if (isset($this->daemon->{$property}) && !is_callable($this->daemon->{$property})) {
            return $this->daemon->{$property};
        }
        return null;
    }





    /**
     * Re-run a previous call by passing in the call's struct.
     * Note: When calls are re-run a retry=1 property is added, and that is incremented for each re-call. You should check
     * that value to avoid re-calling failed methods in an infinite loop.
     *
     * Part of the Daemon API - Use from your daemon to retry a given call
     *
     * @example You set a timeout handler using onTimeout. The worker will pass the timed-out call to the handler as a
     * stdClass object. You can re-run it by passing the object here.
     * @param stdClass $call
     * @return bool
     */
    public function retry(stdClass $call) {
        if (empty($call->method))
            throw new Exception(__METHOD__ . " Failed. A valid call struct is required.");

        $call->status = self::UNCALLED;
        $call->time = array(microtime(true));
        $call->retries++;

        $this->log("Retrying Call {$call->id} To `{$call->method}`");
        return $this->call($call);
    }

    /**
     * Determine the status of a given call. Call ID's are returned when a job is called. Important to note that
     * call ID's are only unique within this worker and this execution.
     *
     * Part of the Daemon API - Use from your daemon to determine the status of a given call
     *
     * @param integer $call_id
     * @return int  Return a status int - See status constants in this class
     */
    public function status($call_id) {
        if (isset($this->calls[$call_id]))
            return $this->calls[$call_id]->status;

        return null;
    }

    /**
     * Set a callable that will called whenever a timeout is enforced on a worker.
     * The offending $call stdClass will be passed-in. Can be passed to retry() to re-try the call. Will have a
     * `retries=N` property containing the number of times it's been sent thru retry().
     *
     * Part of the Daemon API - Use from your daemon to set a Timeout handler
     *
     * @param callable $on_timeout
     * @throws Exception
     */
    public function onTimeout($on_timeout)
    {
        if (!is_callable($on_timeout))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_timeout = $on_timeout;
    }

    /**
     * Set a callable that will be called when a worker method completes.
     * The $call stdClass will be passed-in -- with a `return` property.
     *
     * Part of the Daemon API - Use from your daemon to set a Return handler
     *
     * @param callable $on_return
     * @throws Exception
     */
    public function onReturn($on_return)
    {
        if (!is_callable($on_return))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_return = $on_return;
    }

    /**
     * Set the timeout for methods called on this worker. When a timeout happens, the onTimeout() callback is called.
     *
     * Part of the Daemon API - Use from your daemon to set a timeout for all worker calls.
     *
     * @param $timeout
     * @throws Exception
     */
    public function timeout($timeout)
    {
        if (!is_numeric($timeout))
            throw new Exception(__METHOD__ . " Failed. Numeric value expected.");

        $this->timeout = $timeout;
    }

    /**
     * Set the number of concurrent workers. No limit is hard-coded, but processes are expensive and you should use
     * the minimum number of workers necessary. In `lazy` forking strategy, the processes are forked one-by-one, as
     * needed. This is avoided when your loop_interval is very short (we don't want to be forking processes if you
     * need to loop every half second, for example) but it's the most ideal setting. Read more about the forking strategy
     * for more information.
     *
     * Part of the Daemon API - Use from your daemon to set the number of concurrent asynchronous worker processes.
     *
     * @param int $workers
     * @throws Exception
     */
    public function workers($workers)
    {
        if (!is_int($workers))
            throw new Exception(__METHOD__ . " Failed. Integer value expected.");

        $this->workers = $workers;
    }

    /**
     * Does the worker have at least one idle process?
     *
     * Part of the Daemon API - Use from your daemon to determine if any of your daemon's worker processes are idle
     *
     * @example Use this to implement a pattern where there is always a background worker working. Suppose your daemon writes results to a file
     *          that you want to upload to S3 continuously. You could create a worker to do the upload and set ->workers(1). In your execute() method
     *          if the worker is idle, call the upload() method. This way it should, at all times, be uploading the latest results.
     *
     * @return bool
     */
    public function is_idle() {
        return $this->workers > count($this->running_calls);
    }

    /**
     * Allocate the total size of shared memory that will be allocated for passing arguments and return values to/from the
     * worker processes. Should be sufficient to hold the working set of each worker pool.
     *
     * This is can be calculated roughly as:
     * ([Max Size Of Arguments Passed] + [Max Size of Return Value]) * ([Number of Jobs Running Concurrently] + [Number of Jobs Queued, Waiting to Run])
     *
     * The memory used by a job is freed after a worker ack's the job as complete and the onReturn handler is called.
     * The total pool of memory allocated here is freed when:
     * 1) The daemon is stopped and no messages are left in the queue.
     * 2) The daemon is restarted without the --recoverworkers flag (In this case the memory is freed and released and then re-allocated.
     *    This is useful if you need to resize the shared memory the worker uses or you just want to purge any stale messages)
     *
     * Part of the Daemon API - Use from your Daemon to allocate shared memory used among all worker processes.
     *
     * @default 1 MB
     * @param $bytes
     * @throws Exception
     * @return int
     */
    public function malloc($bytes = null) {
        if ($bytes !== null) {
            if (!is_int($bytes))
                throw new Exception(__METHOD__ . " Failed. Could not set SHM allocation size. Expected Integer. Given: " . gettype($bytes));

            if (is_resource($this->shm))
                throw new Exception(__METHOD__ . " Failed. Can Not Re-Allocate SHM Size. You will have to restart the daemon without the --recoverworkers option to resize.");

            $this->memory_allocation = $bytes;
        }

        return $this->memory_allocation;
    }
}
