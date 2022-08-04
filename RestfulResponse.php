<?php


namespace app\components\rest;


use yii\base\Component;
use yii\web\Response;

class RestfulResponse extends Component
{
    private Response $response;

    public function __construct($config = [], Response $response)
    {
        $this->response = $response;
        $this->response->format = Response::FORMAT_JSON;
        $this->response->setStatusCode(200);
        parent::__construct($config);
    }

    public function notFound($responseMessage = '')
    {
        if (!$responseMessage) {
            $responseMessage = \Yii::t('common', 'Not Found');
        }
        $this->response->setStatusCode(404);
        $this->response->data = ['error' => $responseMessage];

        return $this->response;
    }

    public function dataIsNotValid($responseMessage = '')
    {
        if (!$responseMessage) {
            $responseMessage = \Yii::t('common', 'Not valid');
        }
        $this->response->setStatusCode(422);
        $this->response->data = ['error' => $responseMessage];

        return $this->response;
    }

    public function methodNotAllowed($responseMessage = '')
    {
        if (!$responseMessage) {
            $responseMessage = \Yii::t('common', 'Method Not Allowed');
        }
        $this->response->setStatusCode(405);
        $this->response->data = ['error' => $responseMessage];

        return $this->response;
    }

    public function internalError($responseMessage = '')
    {
        if (!$responseMessage) {
            $responseMessage = \Yii::t('error', 'Internal server error title');
        }
        $this->response->setStatusCode(500);
        $this->response->data = ['error' => $responseMessage];

        return $this->response;
    }

    public function response()
    {
        return $this->response;
    }

    public function ok($data)
    {
        $this->response->data = $data;

        return $this->response;
    }

}
