<?php
abstract class RestController extends Controller {

  protected abstract function getDataProvider();

  public function actionService($id = null) {
    if (Yii::app()->request->getIsPutRequest()) {
      $this->put($id);
    }
    else if (Yii::app()->request->getIsDeleteRequest()) {
      $this->delete($id);
    }
    else {
      $this->renderPartial('/rest/service', array(
      'dataProvider' => $this->getDataProvider()
      ));
    }
  }
  
  /**
   * @return CActiveRecord
   */
  protected abstract function getModel();
  
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

  public function put($id) {
    // Parse the PUT parameters
    //parse_str(file_get_contents('php://input'), $put_vars);
    //$this->logvar($put_vars);
    
    $put_vars = json_decode(file_get_contents('php://input'));
    $model = $this->getObject($id);

    // Try to assign PUT parameters to attributes
    foreach($put_vars as $var => $value) {
      // Does model have this attribute? If not, raise an error
      if($model->hasAttribute($var)) {
        $model->$var = $value;
      }
      else {
        $msg = sprintf('Parameter <b>%s</b> is not allowed for model <b>%s</b>', $var, get_class($model));
        Yii::log($msg, 'warn');
        //$this->_sendResponse(500, $msg);
      }
    }
    // Try to save the model
    if($model->save()) {
      $jsonizer = new Jsonizer();
      $this->_sendResponse(200, $jsonizer->toJson($model));
    }
    else {
      $this->_sendResponse(500, print_r($model->errors, true));
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
      exit;
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
      exit;
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