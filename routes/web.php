<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/post/create', 'pages::post.create');
    Route::livewire('/role/create', 'pages::roles.create-role');
        Route::livewire('/role', 'pages::roles.role');


});

Route::get('/pos', function () {
    return view('pos');
});


require __DIR__.'/settings.php';
