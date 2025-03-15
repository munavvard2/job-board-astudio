<?php
use App\Models\Attribute;
use App\Models\Job;
use App\Services\JobFilterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeAll(function () {
//    Artisan::call('migrate:fresh --seed');
});

test('empty filter string returns original query', function () {
    $query = Job::query();
    $result = JobFilterService::applyFilters($query, null)->toSql();
    expect($result)->toBe($query->toSql());
});

test('basic field filtering applies correct where', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'job_type=full-time');

    expect($result->toSql())->toContain('where "job_type" = ?')
        ->and($result->getBindings())->toBe(['full-time']);
});

test('like operator adds correct where clause', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'title LIKE engineer');

    expect($result->toSql())->toContain('where "title" LIKE ?')
        ->and($result->getBindings())->toBe(['%engineer%']);
});

test('numeric comparison operators work correctly', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'salary>=50000');

    expect($result->toSql())->toContain('where "salary" >= ?')
        ->and($result->getBindings())->toBe(["50000"]);
});

test('multiple values create where in clause', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'job_type=full-time,part-time');

    expect(strtolower($result->toSql()))->toContain('where "job_type" in (?, ?)')
        ->and($result->getBindings())->toBe(['full-time', 'part-time']);
});

test('attribute filter creates correct subquery', function () {
    $query = Job::query();
    Attribute::factory()->create([
        'name' => 'years_experience',
        'type' => 'number_value'
    ]);

    $result = JobFilterService::applyFilters($query, 'attribute:years_experience>=3');

    expect(strtolower($result->toSql()))->toContain('exists')
        ->and($result->toSql())->toContain('"number_value" >= ?');
});

test('invalid attribute filter format throws exception', function () {
    $query = Job::query();

    $this->expectException(Exception::class);
    JobFilterService::applyFilters($query, 'attribute:years_experience:3');
});

test('non existent attribute throws exception', function () {
    $query = Job::query();

    $this->expectException(Exception::class);
    JobFilterService::applyFilters($query, 'attribute:non_existent>=3');

});

test('has any relationship filter creates correct where has', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'languages HAS_ANY (PHP,JavaScript)');

    expect(strtolower($result->toSql()))->toContain('exists')
        ->and($result->toSql())->toContain('"name" in (?, ?)')
        ->and(array_slice($result->getBindings(), -2))->toBe(['PHP', 'JavaScript']);
});

test('location filter uses correct column', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'locations IS_ANY (New York,Remote)');

    expect(strtolower($result->toSql()))->toContain('exists')
        ->and($result->toSql())->toContain('"city" in (?, ?)')
        ->and(array_slice($result->getBindings(), -2))->toBe(['New York', 'Remote']);
});

test('equal to relation filter requires all values', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'languages=(PHP,JavaScript)');

    $sql = $result->toSql();
    expect(substr_count($sql, 'exists'))->toBe(2)
        ->and(substr_count($sql, '"name" = ?'))->toBe(2);
});

test('and operator combines conditions correctly', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'job_type=full-time AND salary>=50000');

    $sql = $result->toSql();
    expect($sql)->toContain('where "job_type" = ?')
        ->and(strtolower($sql))->toContain('and ("salary" >= ?)')
        ->and($result->getBindings())->toBe(['full-time', '50000']);
});

test('or operator combines conditions correctly', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, 'job_type=full-time OR salary>=50000');

    $sql = strtolower($result->toSql());
    expect($sql)->toContain('where (')
        ->and($sql)->toContain('"job_type" = ?')
        ->and($sql)->toContain('or')
        ->and($sql)->toContain('"salary" >= ?');
});

test('nested parentheses group conditions correctly', function () {
    $query = Job::query();

    $result = JobFilterService::applyFilters($query, '(job_type=full-time OR job_type=part-time) AND salary>=50000');

    $sql = strtolower($result->toSql());

    expect($sql)->toContain('where (')
        ->and($sql)->toContain('"job_type" = ? or ("job_type" = ?)')
        ->and($sql)->toContain(') and ("salary" >= ?)');
});

test('unbalanced parentheses throws exception', function () {
    $query = Job::query();

    $this->expectException(Exception::class);
    JobFilterService::applyFilters($query, '(job_type=full-time AND salary>=50000');
});

test('complex filter combines all conditions correctly', function () {
    $query = Job::query();
    Attribute::factory()->create([
        'name' => 'years_experience',
        'type' => 'number_value'
    ]);

    $result = JobFilterService::applyFilters($query,
        '(job_type=full-time AND (languages HAS_ANY (PHP,JavaScript))) AND ' .
        '(locations IS_ANY (New York,Remote)) AND attribute:years_experience>=3'
    );

    $sql = strtolower($result->toSql());

    expect($sql)->toContain('where')
        ->and($sql)->toContain('"job_type" = ?')
        ->and($sql)->toContain('exists')
        ->and($sql)->toContain('"name" in (?, ?)')
        ->and($sql)->toContain('"city" in (?, ?)')
        ->and($sql)->toContain('"number_value" >= ?');
});
