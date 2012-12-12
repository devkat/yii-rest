<?php
abstract class RestController extends CController {

    /**
     * @return CActiveRecord
     */
    protected function getModel() {
        $reflector = new ReflectionClass($this->getModelClassName());
        return $reflector->getMethod('model')->invoke(null);
    }
    
    protected function getModelClassName() {
        $cl = get_class($this);
        return substr($cl, 0, strlen($cl) - strlen('Controller'));
    }

    protected function getDataProvider() {
        return new CActiveDataProvider($this->getModelClassName());
    }

    public function actionService($id = null) {
        if (Yii::app()->request->getIsPutRequest()) {
            $this->put($id);
        }
        else if (Yii::app()->request->getIsPostRequest()) {
            $this->post($id);
        }
        else if (Yii::app()->request->getIsDeleteRequest()) {
            $this->delete($id);
        }
        else {
            if ($id === null) {
                $this->sendDataResponse();
            }
            else {
                $model = $this->getObject($id);
                $this->_sendResponse(200, $this->toJson($model), 'application/json');
            }
        }
    }
    
    protected function toJson($model) {
        $jsonizer = new Jsonizer();
        return json_encode($jsonizer->toJson($model));
    }
    
    protected function getQueryCriteria() {
        $criteria = new CDbCriteria();
        foreach ($_GET as $key => $val) {
            if ($value === '') {
                $criteria->addCondition('false');
            }
            else {
                $criteria->addSearchCondition($key, preg_replace('/\*/', '', $val));
            }
        }
        return $criteria;
    }

    protected function sendDataResponse() {
        header('Content-type: application/json');

        $pageSize = 20;
        $firstItem = 0;

        $dataProvider = $this->getDataProvider();
        $dataProvider->criteria = $this->getQueryCriteria();
        $size = $dataProvider->getTotalItemCount();

        $headers = apache_request_headers();
        if (isset($headers['Range'])) {
            $range = $headers['Range'];
            $matches = array();
            preg_match("/items=(\d+)-(\d*)/", $range, $matches);
            if (count($matches) !== 3) {
                throw new Exception("Invalid range header: $range");
            }
            if ($matches[1] === '0' && $matches[2] === '') {
                $dataProvider->setPagination(false);
            }
            $firstItem = $matches[1];
        }
        else {
            $dataProvider->setPagination(false);
            Yii::log("Range header not set!", 'warn');
        }

        $pagination = $dataProvider->getPagination();
        if ($pagination) {
            $currentPage = floor($firstItem / $pageSize);
            $pagination->setCurrentPage($currentPage);
            $first = $pagination->getOffset();
            $data = $dataProvider->getData();
            $last = $first + count($data) - 1;
        }
        else {
            $data = $dataProvider->getData();
            $first = 0;
            $last = $size - 1;
        }

        header('Content-Range: items '.$first.'-'.$last.'/'.$size);

        $objects = array();
        if (!isset($jsonizer)) {
            $jsonizer = new Jsonizer;
        }

        foreach ($data as $item) {
            $objects[] = $jsonizer->toJson($item);
            //$objects[] = json_encode($item);
        }
        echo json_encode($objects);
    }

    protected function getObject($id) {
        $model = $this->getModel()->findByPk($id);
        if (is_null($model)) {
            $this->_sendResponse(400,
            sprintf("Error: Didn't find any model <b>%s</b> with ID <b>%s</b>.",
            $this->getModel()->tableName(), $id) );
        }
        return $model;
    }

    public function delete($id) {
        $model = $this->getObject($id);
        $tx = $model->dbConnection->beginTransaction();
        $success = $model->delete();
        if ($success) {
            $tx->commit();
            $jsonizer = new Jsonizer();
            $this->_sendResponse(200, json_encode(array("status" => "ok")));
        }
        else {
            $tx->rollback();
            $this->_sendResponse(500, print_r($model->errors, true));
        }
    }
    
    protected function create() {
        $reflector = new ReflectionClass($this->getModelClassName());
        return $reflector->newInstance();
    }
    
    protected function findOrCreate($attributes) {
        return $this->create();
    }
    
    public function post() {
        $attributes = json_decode(file_get_contents('php://input'));
        if ($attributes === null) {
            $attributes = $_POST;
        }
        
        $model = $this->findOrCreate($attributes);
        
        $tx = $model->dbConnection->beginTransaction();
        $this->populateAttributes($model, $attributes);
        if ($model->save()) {
            $tx->commit();
            header("Location: ".$this->createUrl('view', array('id' => $model->id)));
            $this->_sendWrappedResponse(201, $this->toJson($model), $model);
        }
        else {
            $tx->rollback();
            $this->_sendWrappedResponse(500, json_encode($model->errors), $model);
        }
    }
    
    protected function _sendWrappedResponse($code, $string, $model) {
        $wrappedString = $string;
        $contentType = 'application/json';
        $uploadAttrs = $this->getUploadAttributes($model);
        if (count($uploadAttrs) > 0) {
            $wrappedString = "<textarea>$string</textarea>";
            $contentType= 'text/html';
        }
        $this->_sendResponse($code, $wrappedString, $contentType);
    }
    
    protected function getUploadAttributes($model) {
        return method_exists($model, 'uploadAttributes') ? $model->uploadAttributes() : array();
    }
    
    protected function populateAttributes($model, $attributes) {
        foreach ($this->getUploadAttributes($model) as $attr) {
            $model->$attr = CUploadedFile::getInstanceByName($attr);
        }
        foreach($attributes as $var => $value) {
            // Does model have this attribute? If not, raise an error
            if ($model->hasAttribute($var)) {
                $model->$var = $value;
            }
            else {
                $msg = sprintf('Parameter <b>%s</b> is not allowed for model <b>%s</b>', $var, get_class($model));
                Yii::log($msg, 'warn');
                //$this->_sendResponse(500, $msg);
            }
        }
    }

    public function put($id) {
        // Parse the PUT parameters
        //parse_str(file_get_contents('php://input'), $put_vars);
        //$this->logvar($put_vars);

        $model = $this->getObject($id);
        $attributes = json_decode(file_get_contents('php://input'));
        $this->populateAttributes($model, $attributes);

        // Try to save the model
        if($model->save()) {
            $this->_sendResponse(200, $this->toJson($model), 'application/json');
        }
        else {
            $this->_sendResponse(400, json_encode($model->errors), 'application/json');
        }
    }


    private function _sendResponse($status = 200, $body = '', $content_type = 'text/html')
    {
        // set the status
        $status_header = 'HTTP/1.1 ' . $status . ' ' . $this->_getStatusCodeMessage($status);
        header($status_header);
        // and the content type
        header('Content-type: ' . $content_type);

        // pages with body are easy
        if($body != '')
        {
            // send the body
            echo $body;
            return;
        }
        // we need to create the body if none is passed
        else
        {
            // create some body messages
            $message = '';

            // this is purely optional, but makes the pages a little nicer to read
            // for your users.  Since you won't likely send a lot of different status codes,
            // this also shouldn't be too ponderous to maintain
            switch($status)
            {
                case 401:
                    $message = 'You must be authorized to view this page.';
                    break;
                case 404:
                    $message = 'The requested URL ' . $_SERVER['REQUEST_URI'] . ' was not found.';
                    break;
                case 500:
                    $message = 'The server encountered an error processing your request.';
                    break;
                case 501:
                    $message = 'The requested method is not implemented.';
                    break;
            }

            // servers don't always have a signature turned on
            // (this is an apache directive "ServerSignature On")
            $signature = ($_SERVER['SERVER_SIGNATURE'] == '') ? $_SERVER['SERVER_SOFTWARE'] . ' Server at ' . $_SERVER['SERVER_NAME'] . ' Port ' . $_SERVER['SERVER_PORT'] : $_SERVER['SERVER_SIGNATURE'];

            // this should be templated in a real-world solution
            $body = '
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
    <title>' . $status . ' ' . $this->_getStatusCodeMessage($status) . '</title>
</head>
<body>
    <h1>' . $this->_getStatusCodeMessage($status) . '</h1>
    <p>' . $message . '</p>
    <hr />
    <address>' . $signature . '</address>
</body>
</html>';

            echo $body;
            return;
        }
    }

    private function _getStatusCodeMessage($status)
    {
        // these could be stored in a .ini file and loaded
        // via parse_ini_file()... however, this will suffice
        // for an example
        $codes = Array(
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        );
        return (isset($codes[$status])) ? $codes[$status] : '';
    }

    protected function getSearchCriteriaFromRequest($attrs) {
        $criteria = new CDbCriteria();

        foreach ($_GET as $key => $val) {
            $matches = array();
            // + is replaced by _
            if (preg_match('/^sort\(([_\-])([a-zA-Z]+)\)$/', $key, $matches)) {
                $dir = $matches[1];
                $dirString = $dir === '_' ? 'ASC' : 'DESC';
                $attr = $matches[2];
                $criteria->order = $attr.' '.$dirString;
            }
        }

        foreach ($attrs as $attr) {
            if (isset($_GET[$attr])) {
                $val = $_GET[$attr];
                if (preg_match('/\d+/', $val)) {
                    $criteria->addCondition($attr.'='.intval($val));
                }
                else {
                    throw new Exception("Invalid value ".$val." for attribute ".$attr);
                }
            }
        }
        return $criteria;
    }

}