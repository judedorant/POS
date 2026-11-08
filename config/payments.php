<?php
return ['stripe_key'=>env('STRIPE_SECRET_KEY'),'webhook_secret'=>env('STRIPE_WEBHOOK_SECRET'),'return_url'=>env('STRIPE_RETURN_URL',env('APP_URL','http://localhost:8000'))];
