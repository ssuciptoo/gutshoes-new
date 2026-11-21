<?php

use App\Jobs\ExpirePendingOrders;
use App\Jobs\ReconcilePendingPayments;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpirePendingOrders)->everyMinute()->withoutOverlapping();
Schedule::job(new ReconcilePendingPayments)->everyFiveMinutes()->withoutOverlapping();
