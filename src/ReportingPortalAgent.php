<?php

use Codeception\Exception\ModuleRequireException;
use Codeception\Platform\Extension;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use ReportPortalBasic\Enum\ItemStatusesEnum;
use ReportPortalBasic\Enum\ItemTypesEnum;
use ReportPortalBasic\Enum\LogLevelsEnum;
use ReportPortalBasic\Service\ReportPortalHTTPService;
use \Codeception\Events;
use \Codeception\Event\SuiteEvent;
use \Codeception\Event\TestEvent;
use \Codeception\Event\FailEvent;
use \Codeception\Event\StepEvent;
use \Codeception\Event\PrintResultEvent;

/**
 * Class ReportPortalAgent
 *
 */
class ReportingPortalAgent extends Extension
{
    const STRING_LIMIT = 20000;
    const COMMENT_STER_STRING = '$this->getScenario()->comment($description);';
    const PICTURE_CONTENT_TYPE = 'png';
    const WEBDRIVER_MODULE_NAME = 'WebDriver';
    const EXAMPLE_JSON_WORD = 'example';
    const COMMENT_STEPS_DESCRIPTION = 'comment';
    const TOO_LONG_CONTENT = 'Content too long to display. See complete response in ';

    private $isCommentStep = false;
    private $isFirstSuite = false;
    private $isFailedLaunch = false;

    private $launchName;
    private $launchDescription;
    private $testName;
    private $testDescription;
    private $allowFailure;
    private $connectionFailed;

    private $rootItemID;
    private $testItemID;
    private $stepItemID;
    private $lastFailedStepItemID;
    private $lastStepItemID;

    private $stepCounter;

    /**
     *
     * @var ReportPortalHTTPService
     */
    protected static $httpService;

    // list events to listen to
    public static $events = array(
        Events::SUITE_BEFORE => 'beforeSuite',
        Events::TEST_START => 'beforeTestExecution',
        Events::TEST_BEFORE => 'beforeTest',
        Events::STEP_BEFORE => 'beforeStep',
        'step.fail' => 'afterStepFail',
        Events::STEP_AFTER => 'afterStep',
        Events::TEST_AFTER => 'afterTest',
        Events::TEST_END => 'afterTestExecution',
        Events::TEST_FAIL => 'afterTestFail',
        Events::TEST_ERROR => 'afterTestError',
        Events::TEST_INCOMPLETE => 'afterTestIncomplete',
        Events::TEST_SKIPPED => 'afterTestSkipped',
        Events::TEST_SUCCESS => 'afterTestSuccess',
        Events::TEST_FAIL_PRINT => 'afterTestFailAdditional',
        Events::RESULT_PRINT_AFTER => 'afterTesting',
        Events::SUITE_AFTER => 'afterSuite'
    );

    /**
     * Configure http client.
     */
    private function configureClient()
    {
        $UUID = $this->config['UUID'] ?? getenv('REPORT_PORTAL_UUID');
        $projectName = $this->config['projectName'] ?? getenv('REPORT_PORTAL_PROJECT_NAME');
        $host = $this->config['host'] ?? getenv('REPORT_PORTAL_HOST');
        $timeZone = $this->config['timeZone'] ?? getenv('REPORT_PORTAL_TIMEZONE') ?? '.000+01:00';
        $this->launchName = $this->config['launchName'] ?? getenv('REPORT_PORTAL_LAUNCH_NAME');
        $this->launchDescription = $this->config['launchDescription'] ?? getenv('REPORT_PORTAL_LAUNCH_DESCRIPTION');
        $this->allowFailure = $this->config['allowFailure'] ?? getenv('REPORT_PORTAL_ALLOW_FAILURE') ?? true;
        $this->connectionFailed = false;
        $isHTTPErrorsAllowed = true;
        $baseURI = sprintf(ReportPortalHTTPService::BASE_URI_TEMPLATE, $host);
        ReportPortalHTTPService::configureClient($UUID, $baseURI, $host, $timeZone, $projectName, $isHTTPErrorsAllowed);
        self::$httpService = new ReportPortalHTTPService();
    }

    /**
     * Before suite action
     *
     * @param SuiteEvent $e
     *
     * @throws Throwable
     */
    public function beforeSuite(SuiteEvent $e)
    {
        if ($this->isFirstSuite === false) {
            $this->configureClient();
            try {
                self::$httpService::launchTestRun($this->launchName, $this->launchDescription,
                    ReportPortalHTTPService::DEFAULT_LAUNCH_MODE, []);
            } catch (\Throwable $e) {
                $this->connectionFailed = true;
                if(!$this->allowFailure) {
                    throw $e;
                }
                $this->writeln("Cannot connect to reporting portal. Exception message: " . $e->getMessage());
            }
            $this->isFirstSuite = true;
        }
        if($this->connectionFailed) {
            return;
        }
        $suiteBaseName = $e->getSuite()->getBaseName();
        $response = self::$httpService::createRootItem($suiteBaseName, $suiteBaseName . ' tests', []);
        $this->rootItemID = self::getID($response);
    }

    /**
     * After suite action
     *
     * @param SuiteEvent $e
     *
     * @throws GuzzleException
     */
    public function afterSuite(SuiteEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        self::$httpService::finishRootItem();
    }

    /**
     * Before test execution action
     *
     * @param TestEvent $e
     */
    public function beforeTestExecution(TestEvent $e)
    {

    }

    /**
     * Before test action
     *
     * @param TestEvent $e
     *
     * @throws GuzzleException
     */
    public function beforeTest(TestEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $this->stepCounter = 0;
        $testName = $e->getTest()->getMetadata()->getName();

        /**
         * Create string with params of test
         */
        $stringWithParams = '';
        $arrayWithParams = $e->getTest()->getMetadata()->getCurrent();
        if (array_key_exists(self::EXAMPLE_JSON_WORD, $arrayWithParams)) {
            $exampleParams = $arrayWithParams[self::EXAMPLE_JSON_WORD];
            foreach ($exampleParams as $key => $value) {
                $stringWithParams .= $value . '; ';
            }
            if (!empty($stringWithParams)) {
                $stringWithParams = substr($stringWithParams, 0, -2);
                $stringWithParams = ' (' . $stringWithParams . ')';
            }
        }

        $this->testName = $testName . $stringWithParams;
        $this->testDescription = $stringWithParams;
        $response = self::$httpService::startChildItem($this->rootItemID, $this->testDescription, $this->testName,
        ItemTypesEnum::TEST, []);
        $id = self::getID($response);
        if(!empty($id)) {
            $this->testItemID = $id;
        }
    }

    /**
     * After test action
     *
     * @param TestEvent $e
     */
    public function afterTest(TestEvent $e)
    {

    }

    /**
     * After test execution action
     *
     * @param TestEvent $e
     */
    public function afterTestExecution(TestEvent $e)
    {

    }

    /**
     * After test fail action
     *
     * @param FailEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTestFail(FailEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $this->setFailedLaunch();
        $trace = $e->getFail()->getTraceAsString();
        $message = $e->getFail()->getMessage();
        $fileName = $e->getTest()->getMetadata()->getFilename();
        $testName = $e->getTest()->getMetadata()->getName();
        $step = $fileName . ':' . $testName;
        self::$httpService::addLogMessage($this->lastFailedStepItemID, $step, LogLevelsEnum::ERROR);
        if (strpos($message, self::TOO_LONG_CONTENT) === false) {
            self::$httpService::addLogMessage($this->lastFailedStepItemID, $message, LogLevelsEnum::ERROR);
        }
        self::$httpService::addLogMessage($this->lastFailedStepItemID, $trace, LogLevelsEnum::ERROR);
        self::$httpService::finishItem($this->testItemID, ItemStatusesEnum::FAILED, $this->testDescription);
    }

    /**
     * After test fail additional action
     *
     * @param FailEvent $e
     */
    public function afterTestFailAdditional(FailEvent $e)
    {
        $this->setFailedLaunch();
    }

    /**
     * After test error action
     *
     * @param FailEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTestError(FailEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        if(empty($this->testItemID)) {
            codecept_debug("No Reporting ID for test." . $this->testName);
            return;
        }
        self::$httpService::finishItem($this->testItemID, ItemStatusesEnum::STOPPED, $this->testDescription);
        $this->setFailedLaunch();
    }

    /**
     * After test incomplete action
     *
     * @param FailEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTestIncomplete(FailEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        self::$httpService::finishItem($this->testItemID, ItemStatusesEnum::CANCELLED, $this->testDescription);
        $this->setFailedLaunch();
    }

    /**
     * After test skipped action
     *
     * @param FailEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTestSkipped(FailEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $this->beforeTest($e);
        $trace = $e->getFail()->getTraceAsString();
        $message = $e->getFail()->getMessage();
        self::$httpService::addLogMessage($this->testItemID, $message, LogLevelsEnum::ERROR);
        self::$httpService::addLogMessage($this->testItemID, $trace, LogLevelsEnum::ERROR);
        self::$httpService::finishItem($this->testItemID, ItemStatusesEnum::SKIPPED, $message);
        $this->setFailedLaunch();
    }

    /**
     * After test success action
     *
     * @param TestEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTestSuccess(TestEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        self::$httpService::finishItem($this->testItemID, ItemStatusesEnum::PASSED, $this->testDescription);
    }

    /**
     * Before step action
     *
     * @param StepEvent $e
     *
     * @throws GuzzleException
     */
    public function beforeStep(StepEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $this->stepCounter++;
        $pairs = explode(':', $e->getStep()->getLine());
        $fileAddress = $pairs[0];
        if(empty($fileAddress) === true) {
            return;
        }
        $lineNumber = $pairs[1] ?? 0;
        $fileLines = file($fileAddress);
        $stepName = $fileLines[$lineNumber - 1];
        $stepAsString = $e->getStep()->toString(self::STRING_LIMIT);
        $this->isCommentStep = strpos($stepName, self::COMMENT_STER_STRING) !== false;
        if ($this->isCommentStep) {
            $stepName = $stepAsString;
        }
        $response = self::$httpService::startChildItem($this->testItemID, '', $stepName, ItemTypesEnum::STEP, []);
        $this->stepItemID = self::getID($response);
        self::$httpService::setStepItemID($this->stepItemID);
        $this->lastStepItemID = $this->stepItemID;
    }

    /**
     * After step action
     *
     * @param StepEvent $e
     *
     * @throws ModuleRequireException
     * @throws GuzzleException
     */
    public function afterStep(StepEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $this->stepCounter--;
        $stepToString = $e->getStep()->toString(self::STRING_LIMIT);
        $isFailedStep = $e->getStep()->hasFailed();
        $isWebDriverModuleEnabled = $this->hasModule(self::WEBDRIVER_MODULE_NAME);
        if ($isFailedStep && $isWebDriverModuleEnabled) {
            $screenShot = $this->getModule(self::WEBDRIVER_MODULE_NAME)->webDriver->takeScreenshot();
            self::$httpService::addLogMessageWithPicture($this->stepItemID, $stepToString, LogLevelsEnum::ERROR,
                $screenShot, self::PICTURE_CONTENT_TYPE);
        }
        $status = self::getStatusByBool($isFailedStep);
        if ($this->isCommentStep) {
            $description = self::COMMENT_STEPS_DESCRIPTION;
        } else {
            $description = $e->getStep()->toString(self::STRING_LIMIT);
        }
        self::$httpService::finishItem($this->stepItemID, $status, $description);
        self::$httpService::setStepItemIDToEmpty();
        if ($isFailedStep) {
            $this->lastFailedStepItemID = $this->stepItemID;
        }
    }

    /**
     * After step fail action
     *
     * @param FailEvent $e
     */
    public function afterStepFail(FailEvent $e)
    {

    }

    /**
     * After testing action
     *
     * @param PrintResultEvent $e
     *
     * @throws GuzzleException
     */
    public function afterTesting(PrintResultEvent $e)
    {
        if($this->connectionFailed) {
            return;
        }
        $status = self::getStatusByBool($this->isFailedLaunch);
        $HTTPResult = self::$httpService::finishTestRun($status);
        self::$httpService::finishAll($HTTPResult);
    }

    /**
     * Get status for HTTP request from boolean variable
     *
     * @param bool $isFailedItem
     * @return string
     */
    private static function getStatusByBool(bool $isFailedItem): string
    {
        if ($isFailedItem) {
            $stringItemStatus = ItemStatusesEnum::FAILED;
        } else {
            $stringItemStatus = ItemStatusesEnum::PASSED;
        }
        return $stringItemStatus;
    }

    /**
     *Set isFailedLaunch to true
     */
    private function setFailedLaunch()
    {
        if ($this->stepCounter !== 0) {
            $this->lastFailedStepItemID = $this->lastStepItemID;
        }
        $this->isFailedLaunch = true;
    }

    /**
     * Get ID from response
     *
     * @param ResponseInterface $HTTPResponse
     * @return string
     */
    private static function getID(ResponseInterface $HTTPResponse): string
    {
        return json_decode($HTTPResponse->getBody(), true)['id'];
    }
}
