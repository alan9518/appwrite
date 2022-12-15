<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Request;
use Utopia\App;
use Appwrite\Extend\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, Document $user) {
    preg_match_all('/{(.*?)}/', $label, $matches);
    foreach ($matches[1] ?? [] as $pos => $match) {
        $find = $matches[0][$pos];
        $parts = explode('.', $match);

        if (count($parts) !== 2) {
            throw new Exception('Too less or too many parts', 400, Exception::GENERAL_ARGUMENT_INVALID);
        }

        $namespace = $parts[0] ?? '';
        $replace = $parts[1] ?? '';

        $params = match ($namespace) {
            'user' => (array)$user,
            'request' => $requestParams,
            default => $responsePayload,
        };

        if (array_key_exists($replace, $params)) {
            $label = \str_replace($find, $params[$replace], $label);
        }
    }
    return $label;
};

$databaseListener = function (string $event, array $args, Document $project, Usage $queueForUsage, Database $dbForProject) {
    $value = 1;

    $document   = $args['document'];
    $collection = $args['collection'];

    if ($event === Database::EVENT_DOCUMENT_DELETE) {
        $value = -1;
    }

    /**
     * On Documents that tied by relations like functions>deployments>build || documents>collection>database || buckets>files
     * When we remove a parent document we need to deduct his children aggregation from the project scope
     */
       //var_dump($document->getCollection());
    switch (true) {
        case $document->getCollection() === 'teams':
            $queueForUsage->addMetric("teams", $value); // per project
            break;
        case $document->getCollection() === 'users':
            $queueForUsage->addMetric("users", $value); // per project
            // Project sessions deduction
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $userSessions = (count($document->getAttribute('sessions')));
                $sessions = $dbForProject->getDocument('stats', md5("_inf_sessions"));
                if (!empty($userSessions)) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $sessions->getId(),
                        'value',
                        $userSessions
                    );
                }
            }
            break;
        case $document->getCollection() === 'sessions': // sessions Todo sessions count offset issue
            $queueForUsage->addMetric("sessions", $value); // per project
            break;
        case $document->getCollection() === 'databases': // databases
            $queueForUsage->addMetric("databases", $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                //Project collections deduction
                $dbCollections      = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".collections"));
                $projectCollections = $dbForProject->getDocument('stats', md5("_inf_collections"));
                if (!$dbCollections->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectCollections->getId(),
                        'value',
                        $dbCollections['value']
                    );
                }

                //Project documents deduction
                $dbDocuments      = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".documents"));
                $projectDocuments = $dbForProject->getDocument('stats', md5("_inf_documents"));
                if (!$dbDocuments->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectDocuments->getId(),
                        'value',
                        $dbDocuments['value']
                    );
                }
            }
            break;
        case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
            $queueForUsage
               ->addMetric("collections", $value) // per project
               ->addMetric("{$document['databaseId']}" . ".collections", $value) // per database
               ;

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                //Project documents deduction
                $dbDocuments      = $dbForProject->getDocument('stats', md5("_inf_" . "{$document['databaseId']}" . ".documents"));
                $projectDocuments = $dbForProject->getDocument('stats', md5("_inf_documents"));
                if (!$dbDocuments->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectDocuments->getId(),
                        'value',
                        $dbDocuments['value']
                    );
                }
            }
            break;

        case str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_'): //documents
            $queueForUsage
                ->addMetric("documents", $value)  // per project
                ->addMetric("{$document->getAttribute('$databaseId')}" . ".documents", $value) // per database
                ->addMetric("{$document->getAttribute('$databaseId')}" . "." . "{$collection->getId()}" . ".documents", $value)  // per collection
                ;
            break;
        case $document->getCollection() === 'buckets':
            $queueForUsage->addMetric("buckets", $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                //Project files deduction
                $bucketFiles  = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".files"));
                $projectFiles = $dbForProject->getDocument('stats', md5("_inf_files"));
                if (!$bucketFiles->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectFiles->getId(),
                        'value',
                        $bucketFiles['value']
                    );
                }
                //Project files storage deduction
                $bucketStorage  = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".files.storage"));
                $projectStorage = $dbForProject->getDocument('stats', md5("_inf_files.storage"));
                if (!$bucketStorage->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectStorage->getId(),
                        'value',
                        $bucketStorage['value']
                    );
                }
            }
            break;
        case str_starts_with($document->getCollection(), 'bucket_'): // files
            $queueForUsage
                ->addMetric("files", $value) // per project
                ->addMetric("files.storage", $document->getAttribute('sizeOriginal') * $value) // per project
                ->addMetric("{$document['bucketId']}" . ".files", $value) // per bucket
                ->addMetric("{$document['bucketId']}" . ".files.storage", $document->getAttribute('sizeOriginal') * $value)// per bucket
                ;
            break;
        case $document->getCollection() === 'functions':
            $queueForUsage->addMetric("functions", $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                //Project deployments deduction
                $functionDeployments = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getId()}" . ".deployments"));
                $projectDeployments  = $dbForProject->getDocument('stats', md5("_inf_deployments"));
                if (!$functionDeployments->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectDeployments->getId(),
                        'value',
                        $functionDeployments['value']
                    );
                }

                //Project deployments storage deduction
                $functionDeploymentsStorage = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getId()}" . ".deployments.storage"));
                $projectDeploymentsStorage  = $dbForProject->getDocument('stats', md5("_inf_function.deployments.storage"));
                if (!$functionDeployments->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectDeploymentsStorage->getId(),
                        'value',
                        $functionDeploymentsStorage['value']
                    );
                }

                //Project builds  deduction
                $functionBuilds = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".builds"));
                $projectBuilds  = $dbForProject->getDocument('stats', md5("_inf_builds"));
                if (!$functionBuilds->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectBuilds->getId(),
                        'value',
                        $functionBuilds['value']
                    );
                }

                //Project builds storage deduction
                $functionBuildsStorage = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".builds.storage"));
                $projectFunctionBuilds  = $dbForProject->getDocument('stats', md5("_inf_builds.storage"));
                if (!$functionBuildsStorage->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectFunctionBuilds->getId(),
                        'value',
                        $functionBuildsStorage['value']
                    );
                }

                //Project executions  deduction
                $functionExecutions = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".executions"));
                $projectExecutions  = $dbForProject->getDocument('stats', md5("_inf_executions"));
                if (!$functionExecutions->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectExecutions->getId(),
                        'value',
                        $functionExecutions['value']
                    );
                }

                //Project executions compute deduction
                $functionExecutionsCompute = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".executions.compute"));
                $projectExecutionsCompute  = $dbForProject->getDocument('stats', md5("_inf_executions.compute"));
                if (!$functionExecutionsCompute->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute(
                        'stats',
                        $projectExecutionsCompute->getId(),
                        'value',
                        $functionExecutionsCompute['value']
                    );
                }
            }
            break;
        case $document->getCollection() === 'deployments':
            $queueForUsage
                ->addMetric("deployments", $value) // per project
                ->addMetric("deployments.storage", $document->getAttribute('size') * $value) // per project
                ->addMetric("{$document['resourceType']}" . "." . "{$document['resourceId']}" . ".deployments", $value)// per function
                ->addMetric("{$document['resourceType']}" . "." . "{$document['resourceId']}" . ".deployments.storage", $document->getAttribute('size') * $value) // per function
                ;
            break;
        case $document->getCollection() === 'builds':
            $deployment = $dbForProject->getDocument('deployments', $document->getAttribute('deploymentId')); // Todo temp fix

            $queueForUsage
                ->addMetric("builds", $value) // per project
                ->addMetric("builds.compute", $document->getAttribute('duration') * $value) // per project
                ->addMetric("{$deployment['resourceId']}" . ".builds", $value) // per function
                ->addMetric("{$deployment['resourceId']}" . ".builds.compute", ($document->getAttribute('duration') * 1000) * $value) // per function
                 ;
            break;
        case $document->getCollection() === 'executions':
            var_dump($document);
            $queueForUsage
                ->addMetric("executions", $value) // per project
                ->addMetric("executions.compute", $document->getAttribute('duration') * $value) // per project
                ->addMetric("{$document['functionId']}" . ".executions", $value) // per function
                ->addMetric("{$document['functionId']}" . ".executions.compute", ($document->getAttribute('duration') * 1000) * $value) // per function
                ;
            break;
        default:
            break;
    }
};

App::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->inject('audits')
    ->inject('mails')
    ->inject('deletes')
    ->inject('database')
    ->inject('dbForProject')
    ->inject('queueForUsage')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $events, Audit $audits, Mail $mails, Delete $deletes, EventDatabase $database, Database $dbForProject, Usage $queueForUsage, string $mode) use ($databaseListener) {

        $route = $utopia->match($request);

        if ($project->isEmpty() && $route->getLabel('abuse-limit', 0) > 0) { // Abuse limit requires an active project scope
            throw new Exception(Exception::PROJECT_UNKNOWN);
        }

        /*
        * Abuse Check
        */
        $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
        $timeLimitArray = [];

        $abuseKeyLabel = (!is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

        foreach ($abuseKeyLabel as $abuseKey) {
            $timeLimit = new TimeLimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $dbForProject);
            $timeLimit
                ->setParam('{userId}', $user->getId())
                ->setParam('{userAgent}', $request->getUserAgent(''))
                ->setParam('{ip}', $request->getIP())
                ->setParam('{url}', $request->getHostname() . $route->getPath())
                ->setParam('{method}', $request->getMethod());
            $timeLimitArray[] = $timeLimit;
        }

        $closestLimit = null;

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        foreach ($timeLimitArray as $timeLimit) {
            foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                if (!empty($value)) {
                    $timeLimit->setParam('{param-' . $key . '}', (\is_array($value)) ? \json_encode($value) : $value);
                }
            }

            $abuse = new Abuse($timeLimit);
            $remaining = $timeLimit->remaining();
            $limit = $timeLimit->limit();
            $time = (new \DateTime($timeLimit->time()))->getTimestamp() + $route->getLabel('abuse-time', 3600);

            if ($limit && ($remaining < $closestLimit || is_null($closestLimit))) {
                $closestLimit = $remaining;
                $response
                    ->addHeader('X-RateLimit-Limit', $limit)
                    ->addHeader('X-RateLimit-Remaining', $remaining)
                    ->addHeader('X-RateLimit-Reset', $time)
                ;
            }

            $enabled = App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';

            if (
                $enabled                // Abuse is enabled
                && !$isAppUser          // User is not API key
                && !$isPrivilegedUser   // User is not an admin
                && $abuse->check()      // Route is rate-limited
            ) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }

        /*
        * Background Jobs
        */
        $events
            ->setEvent($route->getLabel('event', ''))
            ->setProject($project)
            ->setUser($user);

        $mails
            ->setProject($project)
            ->setUser($user);

        $audits
            ->setMode($mode)
            ->setUserAgent($request->getUserAgent(''))
            ->setIP($request->getIP())
            ->setEvent($route->getLabel('audits.event', ''))
            ->setProject($project)
            ->setUser($user);

        $deletes->setProject($project);
        $database->setProject($project);

        $dbForProject
            ->on(Database::EVENT_DOCUMENT_CREATE, fn ($event, $args) => $databaseListener($event, $args, $project, $queueForUsage, $dbForProject))
            ->on(Database::EVENT_DOCUMENT_DELETE, fn ($event, $args) => $databaseListener($event, $args, $project, $queueForUsage, $dbForProject))
        ;

        $useCache = $route->getLabel('cache', false);

        if ($useCache) {
            $key = md5($request->getURI() . implode('*', $request->getParams())) . '*' . APP_CACHE_BUSTER;
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
            );
            $timestamp = 60 * 60 * 24 * 30;
            $data = $cache->load($key, $timestamp);

            if (!empty($data)) {
                $data = json_decode($data, true);
                $parts = explode('/', $data['resourceType']);
                $type = $parts[0] ?? null;

                if ($type === 'bucket') {
                    $bucketId = $parts[1] ?? null;

                    $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

                    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
                        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                    }

                    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
                    $validator = new Authorization(Database::PERMISSION_READ);
                    $valid = $validator->isValid($bucket->getRead());
                    if (!$fileSecurity && !$valid) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    $parts = explode('/', $data['resource']);
                    $fileId = $parts[1] ?? null;

                    if ($fileSecurity && !$valid) {
                        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
                    } else {
                        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
                    }

                    if ($file->isEmpty()) {
                        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                    }
                }

                $response
                    ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $timestamp) . ' GMT')
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->setContentType($data['contentType'])
                    ->send(base64_decode($data['payload']))
                ;

                $route->setIsActive(false);
            } else {
                $response->addHeader('X-Appwrite-Cache', 'miss');
            }
        }
    });

App::shutdown()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->inject('database')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('queueForUsage')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Event $events, Audit $audits, Delete $deletes, EventDatabase $database, Database $dbForProject, Func $queueForFunctions, Usage $queueForUsage, string $mode) use ($parseLabel) {

        $responsePayload = $response->getPayload();

        if (!empty($events->getEvent())) {
            if (empty($events->getPayload())) {
                $events->setPayload($responsePayload);
            }

            /**
             * Trigger functions.
             */
            $queueForFunctions
                ->from($events)
                ->trigger();

            /**
             * Trigger webhooks.
             */
            $events
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger realtime.
             */
            if ($project->getId() !== 'console') {
                $allEvents = Event::generateEvents($events->getEvent(), $events->getParams());
                $payload = new Document($events->getPayload());

                $db = $events->getContext('database');
                $collection = $events->getContext('collection');
                $bucket = $events->getContext('bucket');

                $target = Realtime::fromPayload(
                    // Pass first, most verbose event pattern
                    event: $allEvents[0],
                    payload: $payload,
                    project: $project,
                    database: $db,
                    collection: $collection,
                    bucket: $bucket,
                );

                Realtime::send(
                    projectId: $target['projectId'] ?? $project->getId(),
                    payload: $events->getPayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $target['roles'],
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $events->getParam('userId')
                    ]
                );
            }
        }

        $route = $utopia->match($request);
        $requestParams = $route->getParamsValues();
        $user = $audits->getUser();

        /**
         * Audit labels
         */
        $pattern = $route->getLabel('audits.resource', null);
        if (!empty($pattern)) {
            $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            if (!empty($resource) && $resource !== $pattern) {
                $audits->setResource($resource);
            }
        }

        $pattern = $route->getLabel('audits.userId', null);
        if (!empty($pattern)) {
            $userId = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            $user = $dbForProject->getDocument('users', $userId);
            $audits->setUser($user);
        }

        if (!empty($audits->getResource()) && !empty($audits->getUser()->getId())) {
            /**
             * audits.payload is switched to default true
             * in order to auto audit payload for all endpoints
             */
            $pattern = $route->getLabel('audits.payload', true);
            if (!empty($pattern)) {
                $audits->setPayload($responsePayload);
            }

            foreach ($events->getParams() as $key => $value) {
                $audits->setParam($key, $value);
            }
            $audits->trigger();
        }

        if (!empty($deletes->getType())) {
            $deletes->trigger();
        }

        if (!empty($database->getType())) {
            $database->trigger();
        }

        /**
         * Cache label
         */
        $useCache = $route->getLabel('cache', false);
        if ($useCache) {
            $resource = $resourceType = null;
            $data = $response->getPayload();

            if (!empty($data['payload'])) {
                $pattern = $route->getLabel('cache.resource', null);
                if (!empty($pattern)) {
                    $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
                }

                $pattern = $route->getLabel('cache.resourceType', null);
                if (!empty($pattern)) {
                    $resourceType = $parseLabel($pattern, $responsePayload, $requestParams, $user);
                }

                $key = md5($request->getURI() . implode('*', $request->getParams())) . '*' . APP_CACHE_BUSTER;
                $data = json_encode([
                    'resourceType' => $resourceType,
                    'resource' => $resource,
                    'contentType' => $response->getContentType(),
                    'payload' => base64_encode($data['payload']),
                ]) ;

                $signature = md5($data);
                $cacheLog  = $dbForProject->getDocument('cache', $key);
                $accessedAt = $cacheLog->getAttribute('accessedAt', '');
                $now = DateTime::now();
                if ($cacheLog->isEmpty()) {
                    Authorization::skip(fn () => $dbForProject->createDocument('cache', new Document([
                    '$id' => $key,
                    'resource' => $resource,
                    'accessedAt' => $now,
                    'signature' => $signature,
                    ])));
                } elseif (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $accessedAt) {
                    $cacheLog->setAttribute('accessedAt', $now);
                    Authorization::skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), $cacheLog));
                }

                if ($signature !== $cacheLog->getAttribute('signature')) {
                    $cache = new Cache(
                        new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
                    );
                    $cache->save($key, $data);
                }
            }
        }

        var_dump($mode);
        var_dump($project->getId());
        if (
            $project->getId() !== 'console' && $mode !== APP_MODE_ADMIN
        ) {
            $fileSize = 0;
            $file = $request->getFiles('file');

            if (!empty($file)) {
                $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
            }

            $queueForUsage
                ->setProject($project)
                ->addMetric('network.requests', 1)
                ->addMetric("network.inbound", $request->getSize() + $fileSize)
                ->addMetric("network.outbound", $response->getSize())
                ->trigger();
        }
    });
