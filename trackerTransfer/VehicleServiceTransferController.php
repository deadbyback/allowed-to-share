<?php

namespace app\modules\machine\controllers;

use app\components\service\machine\GpsRestrictionBehavior;
use app\models\frontend\IdentityFilterTrait;
use app\modules\machine\models\VehicleServiceTransferForm;
use app\modules\machine\models\VehicleServiceTransferSearch;
use Exception;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

class VehicleServiceTransferController extends Controller
{
    use IdentityFilterTrait;

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['machine/tracker-transfer'],
                    ],
                ],
            ],
            GpsRestrictionBehavior::class => [
                'class' => GpsRestrictionBehavior::class,
            ],
        ];
    }


    public function init()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        ini_set('memory_limit', '2G');
        parent::init();
    }

    /**
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index');
    }


    /**
     * @return array
     */
    public function actionGetVehicleList()
    {
        try {
            $vehicleServiceTransferSearchModel = new VehicleServiceTransferSearch();
            $vehicleList = $vehicleServiceTransferSearchModel->getFormattedVehicleList();

            return [
                'success' => true,
                'activeVehicleList' => $vehicleList['activeVehicleList'],
                'inactiveVehicleList' => $vehicleList['inactiveVehicleList']
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage()
            ];
        }
    }

    public function actionCheckErrorOrRestartedType(VehicleServiceTransferForm $model)
    {
        try {
            $httpRequest = Yii::$app->getRequest();
            $vehicleId = $httpRequest->post();

            $model->transferTime = time();
            $isDetected = $model->checkErrorOrRestartedType($vehicleId);

            return [
                'success' => true,
                'isDetected' => $isDetected
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage()
            ];
        }
    }

    public function actionTransferTracker(VehicleServiceTransferForm $model)
    {
        $httpRequest = Yii::$app->getRequest();
        $model->setAttributes($httpRequest->post());
        if ($model->validate() && $model->createTransfer()) {
            return [
                'success' => true
            ];
        }
        return [
            'success' => false,
            'errors' => implode("\n", $model->getErrorSummary(false))
        ];
    }

    /**
     * @return array
     */
    public function actionCheckFieldWorks(VehicleServiceTransferForm $model)
    {
        try {
            $httpRequest = Yii::$app->getRequest();

            $model->setAttributes($httpRequest->post());
            if ($model->validate()) {
                $fieldWorks = $model->findFieldWorks();

                return [
                    'success' => true,
                    'isDetected' => $fieldWorks['isDetected'],
                    'fieldWorks' => $fieldWorks['items'],
                ];
            }
            return [
                'success' => false,
                'error' => $model->getErrorSummary(false),
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage()
            ];
        }
    }
}
