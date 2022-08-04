<?php

namespace app\modules\machine\models;

use app\components\import\TelemetrySubscriptionLoader;
use app\components\machine\PurposeService;
use app\components\machine\TrackerStorageService;
use app\models\db\Company;
use app\models\db\document\FieldWork;
use app\models\db\Organization;
use app\models\db\Vehicle;
use app\models\db\VehicleTelemetryService;
use app\models\db\VehicleTelemetryServiceSensor;
use app\models\db\VehicleTelemetrySubscription;
use app\models\frontend\IdentityFilterTrait;
use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;
use Yii;
use yii\base\Model;
use yii\helpers\Url;

class VehicleServiceTransferForm extends Model
{
    use IdentityFilterTrait;

    public $organizationId = null;
    public $vehicleFromId = null;
    public $vehicleTelemetryServiceFromId = null;
    public $vehicleToId = null;
    public $transferTime = null;
    private TrackerStorageService $trackerStorageService;
    private $isToTrackerStorage = false;

    private $organizationTimezone;
    private $targetSubscriptionDateTo;
    private $activeVehicleServiceId;
    private $newVehicleServiceId;
    private $allowedStatusList = [
        VehicleTelemetrySubscription::STATUS_ACTIVE,
        VehicleTelemetrySubscription::STATUS_ERROR,
        VehicleTelemetrySubscription::STATUS_RESTARTED,
    ];
    private TelemetrySubscriptionLoader $telemetryLoader;
    private PurposeService $purposeService;

    public function __construct(
        TrackerStorageService $trackerStorageService,
        TelemetrySubscriptionLoader $telemetryLoader,
        $config = []
    ) {
        $this->trackerStorageService = $trackerStorageService;
        $this->telemetryLoader = $telemetryLoader;
        $this->purposeService = new PurposeService();
        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function init()
    {
        parent::init();
        $this->organizationId = Yii::$app->user->identity->organizationId;
    }

    /**
     * @return array the validation rules.
     */
    public function rules()
    {
        return [
            [['vehicleFromId', 'transferTime'], 'required'],
            [['vehicleToId', 'vehicleTelemetryServiceFromId'], 'safe'],
        ];
    }

    /**
     * @return array customized attribute labels
     */
    public function attributeLabels()
    {
        return [
            'vehicleFromId' => Yii::t('vehicle', 'Energy machine where the tracker is installed'),
            'vehicleToId' => Yii::t('vehicle', 'Energy machine where you need to transfer the tracker'),
            'transferTime' => Yii::t('vehicle', 'Transfer date'),
        ];
    }


    /**
     * @return bool
     */
    public function createTransfer()
    {
        $this->vehicleFromId = $this->vehicleFromId['id'];
        if (empty($this->vehicleToId)) {
            $this->vehicleToId = $this->trackerStorageService->ensureCreatedInOrganization()->id;
            $this->isToTrackerStorage = true;
        } else {
            $this->vehicleToId = $this->vehicleToId['id'];
        }
        if (isset($this->transferTime)) {
            $this->transferTime = strtotime($this->transferTime);
        } else {
            $this->transferTime = time();
        }

        $vehicle = Vehicle::findOne($this->vehicleFromId);
        try {
            if ($vehicle !== null) {
                if ($this->vehicleTelemetryServiceFromId === null) {
                    $activeTelemetryService = $vehicle->getActiveTelemetryService()->one();
                    if ($activeTelemetryService === null) {
                        throw new RuntimeException(
                            'Active telemetry service not found for vehicleId=' . $this->vehicleFromId
                        );
                    }
                    $this->activeVehicleServiceId = $activeTelemetryService->id;
                } else {
                    $this->activeVehicleServiceId = $this->vehicleTelemetryServiceFromId;
                }

                $activeSubscription = $this->searchActiveSubscription($this->activeVehicleServiceId);
                if ($activeSubscription !== null) {
                    $this->targetSubscriptionDateTo = $activeSubscription->dateTo;
                    $activeSubscription->dateTo = $this->transferTime;
                    $activeSubscription->save(false);
                    if (!$vehicle->isTrackerStorage) {
                        $this->importData($activeSubscription);
                    }
                }

                $this->untieActiveTelemetryService($vehicle);

                $this->newVehicleServiceId = $this->replicateVehicleTelemetryService();
                if ($this->newVehicleServiceId !== null) {
                    if ($activeSubscription !== null) {
                        $newActiveSubscription = new VehicleTelemetrySubscription();
                        $newActiveSubscription->attributes = $activeSubscription->attributes;
                        $newActiveSubscription->vehicleTelemetryServiceId = $this->newVehicleServiceId;
                        $newActiveSubscription->dateFrom = $this->transferTime;
                        $newActiveSubscription->dateTo = $this->targetSubscriptionDateTo;
                        $newActiveSubscription->typeId = VehicleTelemetrySubscription::TYPE_CREATE;
                        $newActiveSubscription->statusId = VehicleTelemetrySubscription::STATUS_ACTIVE;
                        $newActiveSubscription->save(false);
                    }
                    $futureSubscriptions = $this->searchFutureSubscriptions();
                    foreach ($futureSubscriptions as $futureSubscription) {
                        $this->replicateSubscription($futureSubscription);
                        $this->deleteSubscription($futureSubscription);
                    }
                }

                return true;
            }
            return false;
        } catch (Exception $exception) {
            Yii::error((string)$exception);
            return false;
        }
    }

    /**
     * @param Vehicle $vehicle
     */
    public function untieActiveTelemetryService(Vehicle $vehicle)
    {
        $telemetryService = VehicleTelemetryService::find()
            ->innerJoinWith('vehicle')
            ->where(
                [
                    'organizationId' => $this->getOrganizationId(),
                    '{{VehicleTelemetryService}}.[[id]]' => $this->activeVehicleServiceId,
                ]
            )
            ->limit(1)
            ->one();

        if ($telemetryService === null) {
            return;
        }
        $telemetryService->statusId = VehicleTelemetryService::STATUS_INACTIVE;
        $telemetryService->removeDate = $this->transferTime;
        if ($telemetryService->save()) {
            $journalModel = new VehicleServiceTransferJournal();
            $journalModel->companyId = $this->detectCompanyId($this->vehicleFromId);
            $journalModel->vehicleId = $this->vehicleFromId;
            $journalModel->vehicleServiceId = $telemetryService->id;
            $journalModel->operationType = VehicleServiceTransferJournal::OPERATION_TYPE_DISMANTLING;
            $journalModel->operationDate = $this->transferTime;
            $journalModel->organizationId = $this->organizationId;
            $journalModel->save(false);
        }
    }

    /**
     * Replace VehicleTelemetryServiceSensor from old to new service.
     * @return int | null
     */
    public function replicateVehicleTelemetryService()
    {
        $vehicleTelemetryService = VehicleTelemetryService::findOne(['id' => $this->activeVehicleServiceId]);
        if ($vehicleTelemetryService !== null) {
            $newVehicleTelemetryService = new VehicleTelemetryService();
            $newVehicleTelemetryService->attributes = $vehicleTelemetryService->attributes;
            $newVehicleTelemetryService->installDate = $this->transferTime;
            $newVehicleTelemetryService->removeDate = null;
            if ($this->isToTrackerStorage) {
                $newVehicleTelemetryService->statusId = VehicleTelemetryService::STATUS_INACTIVE;
            } else {
                $newVehicleTelemetryService->statusId = VehicleTelemetryService::STATUS_ACTIVE;
            }

            $newVehicleTelemetryService->vehicleId = $this->vehicleToId;
            $newVehicleTelemetryService->save(false);
            if (!$this->isToTrackerStorage) {
                foreach ($vehicleTelemetryService->purposes as $purpose) {
                    $this->purposeService->setPurpose($newVehicleTelemetryService, $purpose->id);
                }
            }

            $vehicleTelemetryServiceSensors = $vehicleTelemetryService->sensors;
            foreach ($vehicleTelemetryServiceSensors as $vehicleTelemetryServiceSensor) {
                $newVehicleTelemetryServiceSensors = new VehicleTelemetryServiceSensor();
                $newVehicleTelemetryServiceSensors->attributes = $vehicleTelemetryServiceSensor->attributes;
                $newVehicleTelemetryServiceSensors->vehicleTelemetryServiceId = $newVehicleTelemetryService->id;
                $newVehicleTelemetryServiceSensors->save(false);
            }

            $journalModel = new VehicleServiceTransferJournal();
            $journalModel->companyId = $this->detectCompanyId($this->vehicleToId);
            $journalModel->vehicleId = $this->vehicleToId;
            $journalModel->vehicleServiceId = $newVehicleTelemetryService->id;
            $journalModel->operationType = VehicleServiceTransferJournal::OPERATION_TYPE_INSTALLATION;
            $journalModel->operationDate = $this->transferTime;
            $journalModel->organizationId = $this->organizationId;
            $journalModel->save(false);

            return $newVehicleTelemetryService->id;
        }

        return null;
    }

    /**
     * @param VehicleTelemetrySubscription $subscription
     * @return bool
     */
    public function replicateSubscription(VehicleTelemetrySubscription $subscription)
    {
        $newSubscription = new VehicleTelemetrySubscription();
        $newSubscription->attributes = $subscription->attributes;
        $newSubscription->vehicleTelemetryServiceId = (int)$this->newVehicleServiceId;
        $newSubscription->statusId = VehicleTelemetrySubscription::STATUS_ACTIVE;
        $newSubscription->typeId = VehicleTelemetrySubscription::TYPE_CREATE;
        $newSubscription->save(false);

        return true;
    }

    /**
     * @param VehicleTelemetrySubscription $subscription
     * @return bool
     */
    public function deleteSubscription(VehicleTelemetrySubscription $subscription)
    {
        $subscription->statusId = VehicleTelemetrySubscription::STATUS_DELETED;
        $subscription->save(false);
        return true;
    }

    /**
     * @param $subscription
     * @return bool
     */
    public function importData($subscription)
    {
        try {
            $this->telemetryLoader->fullCreateLoad($subscription);
            return true;
        } catch (Exception $exception) {
            Yii::error((string)$exception);
            return false;
        }
    }

    /**
     * @return VehicleTelemetrySubscription|array|null
     */
    public function searchActiveSubscription($activeVehicleServiceId)
    {
        return VehicleTelemetrySubscription::find()
            ->andWhere(['vehicleTelemetryServiceId' => $activeVehicleServiceId])
            ->andWhere(['IN', 'statusId', $this->allowedStatusList])
            ->andWhere(['typeId' => VehicleTelemetrySubscription::TYPE_CREATE])
            ->whereDateIntervalIntersect($this->transferTime, $this->transferTime)
            ->one();
    }

    /**
     * @return VehicleTelemetrySubscription[]|array
     */
    public function searchFutureSubscriptions()
    {
        return VehicleTelemetrySubscription::find()
            ->andWhere(['IN', 'statusId', $this->allowedStatusList])
            ->andWhere(['typeId' => VehicleTelemetrySubscription::TYPE_CREATE])
            ->andWhere(['vehicleTelemetryServiceId' => $this->activeVehicleServiceId])
            ->andWhere(['>=', 'dateFrom', $this->transferTime])
            ->orderBy(['vehicleTelemetryServiceId' => SORT_ASC, 'dateFrom' => SORT_ASC])
            ->all();
    }

    /**
     * @param $vehicleId
     * @return int
     */
    private function detectCompanyId($vehicleId)
    {
        $companyId = Company::DEFAULT_NO_COMPANY_ID;
        $vehicle = Vehicle::findOne(['id' => $vehicleId]);
        if ($vehicle !== null) {
            $companyId = $vehicle->companyId;
        }

        return $companyId;
    }


    /**
     * @param string $date
     * @return int
     */
    public function getTimestampInUserTimezone(string $date)
    {
        return $this->getDateTimeInUserTimezone($date)->getTimestamp();
    }

    /**
     * @param string $date
     * @return DateTime|false
     */
    protected function getDateTimeInUserTimezone(string $date)
    {
        return DateTime::createFromFormat('d.m.Y', $date, $this->getOrganizationTimezone());
    }

    /**
     * @return DateTimeZone
     */
    public function getOrganizationTimezone()
    {
        if (!$this->organizationTimezone) {
            $organization = Organization::findOne($this->organizationId);
            if ($organization !== null) {
                $this->organizationTimezone = new DateTimeZone($organization->timeZoneId);
            } else {
                $this->organizationTimezone = new DateTimeZone('Europe/Kiev');
            }
        }

        return $this->organizationTimezone;
    }

    private function getDateFromTimestamp()
    {
        return $this->getDateTimeInUserTimezone($this->transferTime)
            ->getTimestamp();
    }

    private function getDateToTimestamp($transferTime)
    {
        return $this->getDateTimeInUserTimezone($transferTime)
            ->setTime(23, 59, 59)
            ->getTimestamp();
    }


    /**
     * @param $vehicleId
     * @return bool
     */
    public function checkErrorOrRestartedType($vehicleId)
    {
        try {
            $vehicle = Vehicle::findOne($vehicleId);
            if ($vehicle !== null) {
                $vehicleServiceId = $vehicle->getActiveTelemetryService()->one()->id;

                $subscription = $this->searchActiveSubscription($vehicleServiceId);
                if ($subscription !== null) {
                    if ($subscription->statusId === VehicleTelemetrySubscription::STATUS_ERROR ||
                        $subscription->statusId === VehicleTelemetrySubscription::STATUS_RESTARTED) {
                        return true;
                    }
                }
            }
            return false;
        } catch (Exception $exception) {
            throw new $exception();
        }
    }

    /**
     * @return array
     */
    public function findFieldWorks()
    {
        $this->vehicleFromId = $this->vehicleFromId['id'];
        $this->vehicleToId = $this->vehicleToId['id'];
        $this->transferTime = strtotime($this->transferTime);
        try {
            $vehicle = Vehicle::findOne($this->vehicleFromId);
            $fieldWorkTracks = [];
            $isDetected = false;

            if ($vehicle !== null) {
                $vehicleServiceId = $vehicle->getActiveTelemetryService()->one()->id;
                $fieldWorkTracks = FieldWork::find()
                    ->innerJoinWith('vehicleTracks', false)
                    ->where(
                        [
                            'AND',
                            ['>=', '{{VehicleTrack}}.[[beginDate]]', $this->transferTime],
                            ['{{VehicleTrack}}.[[vehicleTelemetryServiceId]]' => $vehicleServiceId],
                        ]
                    )
                    ->all();

                $fieldWorkTracks = array_map(function (FieldWork $item) {
                    return [
                        'id' => $item->id,
                        'number' => $item->document->number,
                        'url' => Url::to(['document/fieldWork/detail']) . "?id={$item->id}"
                    ];
                }, $fieldWorkTracks);
                if (!empty($fieldWorkTracks)) {
                    $isDetected = true;
                }
            }

            return [
                'isDetected' => $isDetected,
                'items' => $fieldWorkTracks,
            ];
        } catch (Exception $exception) {
            throw new $exception();
        }
    }
}
