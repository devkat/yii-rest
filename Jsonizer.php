<?php

class Jsonizer {
  
  public function toJson($model) {
    
    $types = array();
    if (method_exists($model, 'getJsonTypes')) {
      $types = array_merge($types, $model->getJsonTypes());
    }
    
    
    $meta = $model->metaData;
    $obj = array();
    if (method_exists($model, 'humanReadableName')) {
      $obj['name'] = $model->humanReadableName();
    }
    
    $excl = array();
    if (method_exists($model, 'getJsonExcludedAttributes')) {
        $excl = $model->getJsonExcludedAttributes();
    }
    
    foreach ($model->getAttributes() as $key => $value) {
      $jsonKey;
      $jsonValue;
      if (preg_match('/Id$/', $key)) {
        $relation = preg_replace('/Id$/', '', $key);
        if ($meta->hasRelation($relation) && method_exists($model->$relation, 'getName')) {
          $jsonKey = $relation;
          $jsonValue = $model->$relation->getName();
        }
      }
      else if (isset($types[$key])) {
        $jsonKey = $key;
        switch ($types[$key]) {
          case 'boolean':
            $jsonValue = $value ? true : false;
            break;
          case 'number':
            $jsonValue = floatval($value);
            break;
          default:
            $jsonValue = $value;
            break;
        }
      }
      else if (in_array($key, $excl)) {
          
      }
      else {
        $jsonKey = $key;
        $jsonValue = $value;
      }
      if ($jsonKey !== null) {
        $obj[$jsonKey] = $jsonValue;
      }
    }
    
    if (method_exists($model, 'toJson')) {
      $obj = array_merge($obj, $model->toJson());
    }
    
    
    /*
    foreach ($meta->relations as $key => $value) {
      if (get_class($value) === 'CBelongsToRelation') {
        $name = $value->name;
        $obj[$key] = $this->toJson($model->$name);
      }
    }
    */
    return $obj;
  }
  
}

?>