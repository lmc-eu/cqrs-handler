LMC CQRS Handlers
=================

[![cqrs-types](https://img.shields.io/badge/cqrs-types-purple.svg)](https://github.com/lmc-eu/cqrs-types)
[![Latest Stable Version](https://img.shields.io/packagist/v/lmc/cqrs-handler.svg)](https://packagist.org/packages/lmc/cqrs-handler)
[![Tests and linting](https://github.com/lmc-eu/cqrs-handler/actions/workflows/tests.yaml/badge.svg)](https://github.com/lmc-eu/cqrs-handler/actions/workflows/tests.yaml)
[![Coverage Status](https://coveralls.io/repos/github/lmc-eu/cqrs-handler/badge.svg?branch=main)](https://coveralls.io/github/lmc-eu/cqrs-handler?branch=main)

> This library contains a base implementation for [CQRS/Types](https://github.com/lmc-eu/cqrs-types).

## Table of contents
- [Installation](#installation)
- Queries
    - [Query Fetcher](#query-fetcher)
    - [Query Handlers](#query-handlers)
    - [Query](#query)
- Commands
    - [Command Sender](#command-sender)
    - [Send Command Handlers](#send-command-handlers)
    - [Command](#command)
- [ProfilerBag](#profiler-bag)

## Installation
```shell
composer require lmc/cqrs-handler
```

## Query Fetcher
Base implementation for a Query Fetcher Interface (see [Types/QueryFetcherInterface](https://github.com/lmc-eu/cqrs-types#query-fetcher-interface)).

It is responsible for
- finding a Query Handler based on Query request type
- handle all Query features
    - caching
        - requires an instance of `Psr\Cache\CacheItemPoolInterface`
    - profiling
        - requires an instance of `Lmc\Cqrs\Handler\ProfilerBag`
- decoding a response from the Query Handler

### Usage
If you are not using a [CQRS/Bundle](https://github.com/lmc-eu/cqrs-bundle) you need to set up a Query Fetcher yourself.

Minimal Initialization
```php
$queryFetcher = new QueryFetcher(
    // Cache
    false,  // disabled cache
    null,   // no cache pool -> no caching

    // Profiling
    null    // no profiler bag -> no profiling
);
```

Full Initialization with all features.
```php
$profilerBag = new ProfilerBag();

$queryFetcher = new QueryFetcher(
    // Cache
    true,           // is cache enabled
    $cache,         // instance of Psr\Cache\CacheItemPoolInterface

    // Profiling
    $profilerBag,   // collection of profiled information

    // Custom handlers
    // NOTE: there is multiple ways of defining handler
    [
        [new TopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST],                      // Array definition of priority
        new OtherHandler(),                                                             // Used with default priority of 50
        new PrioritizedItem(new FallbackHandler(), PrioritizedItem::PRIORITY_LOWEST)    // PrioritizedItem value object definition
    ],

    // Custom response decoders
    // NOTE: there is multiple ways of defining response decoders
    [
        [new TopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST],                      // Array definition of priority
        new OtherDecoder(),                                                             // Used with default priority of 50
        new PrioritizedItem(new FallbackDecoder(), PrioritizedItem::PRIORITY_LOWEST)    // PrioritizedItem value object definition
    ]
);
```

You can add handlers and decoders by `add` methods.
```php
$this->queryFetcher->addHandler(new MyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);
$this->queryFetcher->addDecoder(new MyQueryResponseDecoder(), PrioritizedItem::PRIORITY_HIGH);
```

Fetching a query

You can do whatever you want with a response, we will persist a result into db, for an example or log an error.
```php
// with continuation
$this->queryFetcher->fetch(
    $query,
    fn ($response) => $this->repository->save($response),
    fn (\Throwable $error) => $this->logger->critical($error->getMassage())
);

// with return
try {
    $response = $this->queryFetcher->fetchAndReturn($query);
    $this->repository->save($response);
} catch (\Throwable $error) {
    $this->logger->critical($error->getMessage());
}
```

## Query Handlers
It is responsible for handling a specific Query request and passing a result into `OnSuccess` callback. [See more here](https://github.com/lmc-eu/cqrs-types#query-handler-interface).

### GetCachedHandler
This handler is automatically created `QueryFetcher` and added amongst handlers with priority `80` when an instance of `CacheItemPoolInterface` is passed into `QueryFetcher`.

It supports queries implementing `CacheableInterface` with `cacheTime > 0`. The second condition allows you to avoid caching in queries with `CacheableInterface` by just a cache time value.
There is also `CacheTime::noCache()` named constructor to make it explicit.

It handles a query by retrieving a result out of a cache (if the cache has the item and is `hit` (see [PSR-6](https://www.php-fig.org/psr/psr-6/) for more).

### CallbackQueryHandler
This handler supports a query with request type of `"callable"`, `"Closure"` or `"callback"` (which all stands for a `callable` request).

It simply calls a created request as a function and returns a result to `OnSuccess` callback.

## Query
Query is a request which fetch a data without changing anything. [See more here](https://github.com/lmc-eu/cqrs-types#query-interface)

### CachedDataQuery
This is a predefined implementation for a Query with `CacheableInterface`.

It is handy for in-app queries where you want to use cache for a result. You can also extend it and add more features.

```php
$query = new CallbackQueryHandler(
    fn () => $this->repository->fetchData(),
    new CacheKey('my-data-key'),
    CacheTime::oneHour()
);
```

### ProfiledCachedDataQuery
This is a predefined implementation for a Query with `CacheableInterface` and `ProfileableInterface`.

It is handy for in-app queries where you want to use cache for a result and also profile it. You can also extend it and add more features.

```php
$query = new ProfiledCallbackQueryHandler(
    fn () => $this->repository->fetchData(),
    new CacheKey('my-data-key'),
    CacheTime::oneHour(),
    'my-profiler-key',
    ['additional' => 'data']  // optional
);
```

---

## Command Sender
Base implementation for a Command Sender Interface (see [Types/CommandSenderInterface](https://github.com/lmc-eu/cqrs-types#commmand-sender-interface)).

It is responsible for
- finding a Send Command Handler based on Command request type
- handle all Command features
    - profiling
        - requires an instance of `Lmc\Cqrs\Handler\ProfilerBag`
- decoding a response from the Send Command Handler

### Usage
If you are not using a [CQRS/Bundle](https://github.com/lmc-eu/cqrs-bundle) you need to set up a Command Sender yourself.

Minimal Initialization
```php
$commandSender = new CommandSender(
    // Profiling
    null    // no profiler bag -> no profiling
);
```

Full Initialization with all features.
```php
$profilerBag = new ProfilerBag();

$commandSender = new CommandSender(
    // Profiling
    $profilerBag,   // collection of profiled information

    // Custom handlers
    // NOTE: there is multiple ways of defining handler
    [
        [new TopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST],                      // Array definition of priority
        new OtherHandler(),                                                             // Used with default priority of 50
        new PrioritizedItem(new FallbackHandler(), PrioritizedItem::PRIORITY_LOWEST)    // PrioritizedItem value object definition
    ],

    // Custom response decoders
    // NOTE: there is multiple ways of defining response decoders
    [
        [new TopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST],                      // Array definition of priority
        new OtherDecoder(),                                                             // Used with default priority of 50
        new PrioritizedItem(new FallbackDecoder(), PrioritizedItem::PRIORITY_LOWEST)    // PrioritizedItem value object definition
    ]
);
```

You can add handlers and decoders by `add` methods.
```php
$this->commandSender->addHandler(new MyCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);
$this->commandSender->addDecoder(new MyCommandResponseDecoder(), PrioritizedItem::PRIORITY_HIGH);
```

Sending a command

You can do whatever you want with a response, we will persist a result into db, for an example or log an error.
```php
// with continuation
$this->commandSender->send(
    $command,
    fn ($response) => $this->repository->save($response),
    fn (\Throwable $error) => $this->logger->critical($error->getMassage())
);

// with return
try {
    $response = $this->commandSender->sendAndReturn($query);
    $this->repository->save($response);
} catch (\Throwable $error) {
    $this->logger->critical($error->getMessage());
}
```

## Send Command Handlers
It is responsible for handling a specific Command request and passing a result into `OnSuccess` callback. [See more here](https://github.com/lmc-eu/cqrs-types#send-command-handler-interface).

### CallbackSendCommandHandler
This handler supports a command with request type of `"callable"`, `"Closure"` or `"callback"` (which all stands for a `callable` request).

It simply calls a created request as a function and returns a result to `OnSuccess` callback.

## Command
Command is a request which change a data and may return result data. [See more here](https://github.com/lmc-eu/cqrs-types#command-interface)

### ProfiledDataCommand
This is a predefined implementation for a Command with `ProfileableInterface`.

It is handy for in-app commands where you want to profile it. You can also extend it and add more features.

```php
$command = new ProfiledDataCommand(
    fn () => $this->repository->fetchData(),
    new CacheKey('my-data-key'),
    CacheTime::oneHour(),
    'my-profiler-key',
    ['additional' => 'data']  // optional
);
```

## ProfilerBag
Service, which is a collection of all profiler information in the current request.
If you pass it to the `QueryFetcher` or `CommandSender`, they will profile query/command implementing `ProfileableInterface` to the `ProfilerBag`.

The information inside are used by a `CqrsDataCollector`, which shows them in the Symfony profiler (used in [CQRS/Bundle](https://github.com/lmc-eu/cqrs-bundle)).

### Verbosity
Profiler bag can also hold an information about a verbosity level of profiling.

Levels:
- NORMAL = `empty value` (**default**)
- VERBOSE = `'verbose'`
- DEBUG = `'debug'`

There might be additional data added to the `ProfilerItem` with higher levels of verbosity. 

You can set it by 
```php
$profilerBag->setVerbosity(ProfilerBag::VERBOSITY_VERBOSE);
```
