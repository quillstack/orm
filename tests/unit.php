<?php

declare(strict_types=1);

use Quillstack\Orm\Tests\Integration\RealDatabase;

$tests = [
    \Quillstack\Orm\Tests\Unit\TestGrammars::class,
    \Quillstack\Orm\Tests\Unit\TestManyToMany::class,
    \Quillstack\Orm\Tests\Unit\TestMetadata::class,
    \Quillstack\Orm\Tests\Unit\TestMigrations::class,
    \Quillstack\Orm\Tests\Unit\TestNoQueryPerRow::class,
    \Quillstack\Orm\Tests\Unit\TestReading::class,
    \Quillstack\Orm\Tests\Unit\TestRepository::class,
    \Quillstack\Orm\Tests\Unit\TestUnitOfWork::class,
    \Quillstack\Orm\Tests\Unit\TestValues::class,
];

// Only where one is running. Skipping quietly would make a suite that never touched MySQL
// look exactly like one that did.
if (RealDatabase::isAvailable('pgsql')) {
    $tests[] = \Quillstack\Orm\Tests\Integration\TestPostgres::class;
}

if (RealDatabase::isAvailable('mysql')) {
    $tests[] = \Quillstack\Orm\Tests\Integration\TestMySql::class;
}

return $tests;
