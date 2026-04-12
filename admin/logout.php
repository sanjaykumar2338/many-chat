<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Auth;

Auth::logout();
flash('success', 'You have been signed out.');
redirect('login.php');
