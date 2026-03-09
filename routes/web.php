<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/swarm-sandbox');
});

Route::get('/swarm-sandbox', function () {
    return view('swarm-sandbox');
});
