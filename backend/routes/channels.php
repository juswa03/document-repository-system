<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Each user's own notification stream. NotificationBell.jsx subscribes to
| private-App.Models.User.{id}, so this authorizes exactly the caller's
| own channel — nobody can listen in on someone else's notifications.
| /broadcasting/auth is guarded by auth:sanctum (bootstrap/app.php), so
| $user here is resolved from the bearer token like any other API route.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
