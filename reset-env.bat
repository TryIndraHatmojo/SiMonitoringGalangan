@echo off
echo Membersihkan cache Laravel di Laragon...
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan queue:restart
echo Selesai! Perubahan .env sudah aktif.