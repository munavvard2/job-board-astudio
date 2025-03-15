# JOB Board API with Advanced Filtering

## getting started
- clone the repository
- run `composer install`
- run `php artisan migrate:fresh --seed` this will create 500 jobs (sample Data with Faker) with their location, language and category also some attributes, default database will be sqlite
- run `composer run dev` to start application


## API Endpoints
- the postman collation is attached in this repository, you can import it and test the API
- file located in the root directory of the project name [Job-board-filter-astudio.postman_collection.json](Job-board-filter-astudio.postman_collection.json)
- you can import this file in postman and test the API, please adjust parameters and its values according to the data in the database.

## Filter Parameters ( Query String )
i have taken same format as given in example 
- /api/jobs?filter=(job_type=full-time AND (languages HAS_ANY (PHP,JavaScript))) AND (locations IS_ANY (New York,Remote)) AND attribute:years_experience>=3

## limitations
- i have not implemented the pagination, so it will return all the jobs in the database ( can do it with pagination if required )
- there should be space between the filter parameters, otherwise it will not work eg : `AND (locations IS_ANY (New York,Remote))` should be `AND(locations IS_ANY (New York,Remote))` will not work

## jobAttributes 
- i have added 5 columns named `select_value, number_value, date_value, text_value, boolean_value` and stored this name in `type` column in `attributes` table as you can see in [DatabaseSeeder.php](database/seeders/DatabaseSeeder.php)
- because i didn't wanted to create single column for the value and then later on check the type of the value and then query the database and convert value to suit to database i.e date_value, boolean_value, so i have created 5 columns and stored the type in `type` column
- so when we query the database we can directly query the column with the type of the value we are looking for

## jobFilterService 
- i have added comment in the code to explain the code and why some specific way i had choose to implement the filter parsing, i.e for loop over regex match etc etc
- please checkout the file [JobFilterService.php](app/Services/JobFilterService.php) for more details, where all magic happens
- i have also written some tests in pest php, you can run them by running `php artisan test` or `./vendor/bin/pest` in the root directory of the project
- if you only want to run tests for JobFilterService then you can run `./vendor/bin/pest tests/Unit/JobFilterServiceTest.php`


## database indexing
- i have not added this as time was less and i want to focus on implementing the filter parameters, but we can add indexing to the columns which are being queried in the filter parameters to make the query faster
- we can index the columns `job_type, languages, locations, years_experience` in the `jobs` table and `type` column in the `attributes` table.
- i have tested in my machine with 5000 jobs and its data, response come quickly but with more data it will be slow, so we can add indexing to make it faster

## testing
- i have also written some tests in pest php, you can run them by running `php artisan test` or `./vendor/bin/pest` in the root directory of the project
- if you only want to run tests for JobFilterService then you can run `./vendor/bin/pest tests/Unit/JobFilterServiceTest.php`


## database seeding
- you can checkout file [DatabaseSeeder.php](database/seeders/DatabaseSeeder.php) where all sample data and seeding is done, you can change the number of jobs to be created by changing the value in the file currently it is 500
