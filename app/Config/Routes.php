<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->post('/qual-ncm', 'NCM::qualNcm');
$routes->post('/codigo-ncm', 'NCM::codigoNcm');
