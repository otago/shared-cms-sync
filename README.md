# Shared CMS Sync

Pulls content from another SilverStripe site's GraphQL endpoint into local
records. Extracted from the Auckland website, which has run this pattern
against `online.op.ac.nz` for some time, so that the marketing website can use
it too.

## The two halves

Downloading and mapping are separate steps, and the separation is the point.

**Download** asks the remote site for one dataset and stores the reply verbatim
as a `Snapshot`. Nothing local is touched.

**Sync** reads those snapshots and maps them onto local records. It never opens
a socket.

Splitting them buys three things:

- A mapping you got wrong can be fixed and re-run against the bytes that
  actually came back, without asking the far end for everything again.
- A download that finds no change writes nothing, so an untouched page is not
  rewritten and republished every night.
- The far end being down is a download problem. It does not stop you fixing a
  mapping.

## Using it

Require the module, then subclass the two services per dataset.

```php
class PolicyDownloadService extends DownloadService
{
    public function getEndpointEnvVar(): string { return 'SS_OP_POLICIES_GRAPHQL_ENDPOINT'; }
    public function getQuery(): string          { return self::QUERY; }
    public function getSnapshotClass(): string  { return PolicySnapshot::class; }
    public function getRecords(): iterable      { return PolicyPage::get(); }

    public function getVariablesFor(DataObject $record): ?array
    {
        return $record->RemoteID ? ['id' => $record->RemoteID] : null;
    }
}
```

```php
class PolicySyncService extends SyncService
{
    public function getFieldMap(): array     { return ['title' => 'Title', 'content' => 'Content']; }
    public function getRecords(): iterable   { return PolicyPage::get(); }
    public function getPayloadPath(): string { return 'data.readOnePolicyPage'; }

    public function getSnapshotFor(DataObject $record): ?DataObject
    {
        return PolicySnapshot::get()->filter('PageID', $record->ID)->first();
    }
}
```

Then group them into a task:

```php
class SyncAllTask extends PipelineTask
{
    protected function getServices(): array
    {
        return ['Policies' => PolicySyncService::create()];
    }
}
```

## What the base classes handle

- Talking to the endpoint, including a real error when it answers HTTP 400+
  rather than a confusing "invalid JSON" when a login page comes back instead
- Skipping records with nothing to look up, and carrying on past one that fails
- Comparing decoded JSON, so reordered keys do not read as a change
- Pruning superseded snapshots without deleting the one just written
- Leaving unchanged records alone, and asking the database — not
  `isPublished()`, whose static cache `publishSingle()` does not refresh —
  whether a record is actually live
- Turning the strings `"true"` and `"false"` into booleans, which a boolean
  column would otherwise store as 1 either way

## Configuration

Endpoints come from environment variables, named by each download service, so
dev, test and live point at their own counterparts without a code change.

`GraphQLClient` skips certificate verification off live, where these sites run
behind self-signed certificates. Live always verifies.

## Tests

The module has no test suite of its own yet — it is exercised through the host
sites' suites, which have the database and configuration a `SapphireTest`
needs. Worth adding once the module settles.
