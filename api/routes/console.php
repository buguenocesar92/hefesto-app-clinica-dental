<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Rutas de consola
|-------------------------------------------------------------------------------
|
| Los comandos de ClinicaDental se descubren automaticamente desde
| app/Console/Commands. Se dejan declarados aqui para que quede explicito que
| no hay comandos programados: las tareas periodicas que hacen falta
| (revision de insumos bajo stock, revision de la bitacora) son del despliegue,
| y deben declararse a mano. Ver docs/08.
|
| EL QUE SI IMPORTA
| -----------------
| `clinica:verificar-aislamiento` comprueba, desde el servidor y sin pasar por
| la aplicacion, que las diecisiete tablas de negocio tienen RLS forzado y que
| una consulta sin consultorio falla. Es el comando que hay que correr antes de
| recibir el primer paciente. Ver docs/03.
|
*/