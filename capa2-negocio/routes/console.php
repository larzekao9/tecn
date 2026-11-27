<?php

use Illuminate\Support\Facades\Schedule;

// Avisos automaticos (antes: hilo de avisos del sistema por correo). Lo ejecuta el contenedor "scheduler".
Schedule::command('canal11:avisos')->everyMinute()->withoutOverlapping();
