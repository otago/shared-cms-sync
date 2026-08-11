<?php

namespace Otago\SharedCmsSync\Extension;

use Otago\SharedCmsSync\Admin\SyncAdmin;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;

/**
 * Puts a shortcut to the Sync Data screen in the corner of every CMS page.
 *
 * Syncing is something an editor reaches for while in the middle of something
 * else — they have just changed a policy upstream and want it across now. The
 * menu item is several clicks and a scan away; this is one click from wherever
 * they already are.
 *
 * Ships with the module rather than the site, so both sites get the same
 * shortcut to the same screen (WWW-408).
 */
class SyncFabExtension extends Extension
{
    use Configurable;

    /**
     * Set to false on a site that would rather not have the shortcut.
     *
     * @config
     * @var bool
     */
    private static bool $enabled = true;

    /**
     * @return void
     */
    protected function onAfterInit(): void
    {
        if (!$this->config()->get('enabled')) {
            return;
        }

        // Nothing points a member at a screen they cannot open.
        if (!$this->canSeeSyncAdmin()) {
            return;
        }

        $url = $this->syncAdminUrl();

        Requirements::customScript(
            'window.__sharedCmsSyncUrl = ' . json_encode($url) . ';',
            'shared-cms-sync-url'
        );
        Requirements::css('otago/shared-cms-sync:client/dist/sync-fab.css');
        Requirements::javascript('otago/shared-cms-sync:client/dist/sync-fab.js');
    }

    /**
     * The admin's own address, rather than a hardcoded one, so a site that
     * changes SyncAdmin.url_segment keeps a working shortcut.
     *
     * Rooted at the base URL rather than taken from AdminRootController::
     * admin_url(), which returns a path relative to the CMS <base> tag. That
     * resolves correctly in an href, but the script also compares it against
     * window.location.pathname to decide whether it is already on the sync
     * screen — and "admin/sync" never equals "/admin/sync".
     *
     * @return string
     */
    private function syncAdminUrl(): string
    {
        $segment = (string) SyncAdmin::config()->get('url_segment');

        return Controller::join_links(
            Director::baseURL(),
            AdminRootController::get_admin_route(),
            $segment
        ) . '/';
    }

    /**
     * @return bool
     */
    private function canSeeSyncAdmin(): bool
    {
        $member = Security::getCurrentUser();

        if (!$member) {
            return false;
        }

        return Permission::checkMember($member, ['CMS_ACCESS_' . SyncAdmin::class, 'ADMIN']);
    }
}
