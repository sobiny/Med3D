<?php
use think\facade\Route;

Route::post('api/patientUpsert', 'Api/patientUpsert');
Route::post('api/sceneCreate',   'Api/sceneCreate');
Route::post('api/modelCreate',   'Api/modelCreate');
Route::post('api/imageCreate',   'Api/imageCreate');
Route::get('viewer/scene', 'Viewer/scene');
