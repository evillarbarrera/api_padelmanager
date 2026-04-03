<?php
/**
 * PayPal Subscription Configuration
 */

// Si estás en producción usa 'live', si es pruebas usa 'sandbox'
// Como estamos en una fase de integración, usaremos sandbox por defecto
define('PAYPAL_MODE', 'sandbox'); 

define('PAYPAL_CLIENT_ID', 'AaDdNdD9u3UQPHwYkECkzduiGXAll7ZEKrqG-jIUgJhyQNHzaqGXf4bssmnARVdXAjPqgJN5MGZ4e10p'); 
define('PAYPAL_SECRET', 'EI2PqjzQJAcbcdVGovmTvNQwWHOOqnCBTO0KHKRIjCkR7F6wYCt7CFisHrEwd1i0cUyD905tcaph4Nue');

// IDs de Planes (Se llenarán automáticamente tras ejecutar el script de creación)
define('PAYPAL_PLAN_20_ID', 'P-7W798993Y3711311RM6S5Q2Y'); // Inicial 20
define('PAYPAL_PLAN_40_ID', 'P-3N798993BA817112RM6S5Q2Y'); // Pro 40
define('PAYPAL_PLAN_ELITE_ID', 'P-9A798993CK917113RM6S5Q2Y'); // Elite

define('PAYPAL_API_URL', (PAYPAL_MODE == 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com');
