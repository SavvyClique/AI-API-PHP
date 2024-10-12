# AI-API-PHP
Simple yet functional RESTful API template that is AI-friendly (written in php) with Web Scraper functionality.
w/ Admin control area added.

Designed to allow AI to receive requests via prompt, and grab any information from any location within a few seconds, and update their knowledge cutoff in relative real time.

---

An installation guide for this PHP-based API with admin functionality:

Set up the environment:

Install PHP 7.4 or higher
Install Composer
Install Laravel: composer global require laravel/installer

---

Create a new Laravel project:
laravel new ai-friendly-api
cd ai-friendly-api

---
Install required packages:

composer require laravel/sanctum
composer require weidner/goutte

---

Set up the database:

Create a new MySQL database
Update the .env file with your database credentials

---

Run migrations:
php artisan migrate

---

Set up authentication:
php artisan make:auth

---
Configure the application:

Add the following to your .env file:

APP_PAGE_SIZE=20
APP_RATE_LIMIT="100 per day;10 per hour"
APP_API_KEY=your_secret_api_key

---

Set up rate limiting:

In app/Http/Kernel.php, add the following to the $routeMiddleware array:
php 'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,

---

Implement the API key middleware:

Create a new middleware: php artisan make:middleware CheckApiKey
Implement the middleware to check for the API key in the request header


- Set up the admin middleware:

Create a new middleware: php artisan make:middleware IsAdmin
Implement the middleware to check if the authenticated user is an admin


- Create the necessary views:

Create the admin layout and dashboard views as shown in the code above

---

Set up the storage for scraped files:
php artisan storage:link
---

Run the application:
php artisan serve
---

- This PHP-based API with admin functionality provides the following features:

RESTful API for managing tasks
Web scraper functionality
Admin dashboard for monitoring tasks and scraped data
Configuration page for adjusting API settings
Authentication and authorization for API and admin access
Rate limiting to prevent abuse
Pagination for efficient handling of large datasets

---

The admin can access the dashboard at /admin and the configuration page at /admin/config. From there, they can adjust the page size for pagination, rate limiting rules, and the API key.
To use the API, clients should include the API key in the X-API-Key header of their requests. The API endpoints are protected by authentication middleware and rate limiting.
Remember to implement proper security measures, such as HTTPS, in a production environment. Also, consider adding more robust error handling and logging for production use.
