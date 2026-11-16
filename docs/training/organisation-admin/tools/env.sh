# Source this before any artisan command for the training capture. It pins Laravel to the disposable
# training database even though this repository has a cached config (APP_CONFIG_CACHE points at no file,
# so config is rebuilt from .env plus these overrides). Never run training commands without it.
export APP_CONFIG_CACHE=/nonexistent/peopleos-training-config.php
export DB_DATABASE=hcm_orgadmin_showcase
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export MAIL_MAILER=log
export LOG_CHANNEL=stderr
export TRAINING_BASE_URL=http://127.0.0.1:8091
