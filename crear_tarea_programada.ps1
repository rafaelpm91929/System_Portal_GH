# Script para registrar la tarea programada diaria del colector de respaldos
$taskName = "ColectorRespaldosNAS"
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument '-ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\Scripts\colector_respaldos.ps1"'

$t1 = New-ScheduledTaskTrigger -Daily -At 8:00am
$t2 = New-ScheduledTaskTrigger -Daily -At 2:00pm
$t3 = New-ScheduledTaskTrigger -Daily -At 8:00pm

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger @($t1, $t2, $t3) -Settings $settings -Description "Recolección diaria automática de respaldos NAS y actualización de semáforos en cPanel (8am, 2pm y 8pm)" -Force

Write-Host "Tarea programada '$taskName' configurada con 3 ejecuciones diarias (08:00, 14:00 y 20:00)."
Get-ScheduledTask -TaskName $taskName | Format-List TaskName, State, Description
