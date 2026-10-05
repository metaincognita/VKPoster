<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Permission matrix, read as `permissions` in `Config`: permission => roles that hold it. This file is the single source of truth for
 * "who may do what" inside a workspace (`Permissions` reads it, templates ask `can('…')`).
 *
 * The `client` role (an agency's customer) holds only the viewing permissions, and only for the channels
 * assigned to that person (`member_channel_access`).
 * Which members a role may manage is a separate, hierarchical rule (`MemberPolicy`).
 */
return static fn (Env $env): array => [
    // Money and the existence of the workspace.
    'workspace.billing' => ['owner'],
    'workspace.delete' => ['owner'],
    'workspace.transfer' => ['owner'],

    // Workspace settings, people and connected channels.
    'workspace.settings' => ['owner', 'admin'],
    'members.manage' => ['owner', 'admin'],
    'channels.manage' => ['owner', 'admin'],
    // Source configuration has no per-source access lists in the initial skeleton.
    'sources.view' => ['owner', 'admin'],
    'sources.manage' => ['owner', 'admin'],
    // The channel list (a restricted member sees only the channels assigned to them); clients work through the calendar only.
    'channels.view' => ['owner', 'admin', 'editor', 'author', 'viewer'],
    'audit.view' => ['owner', 'admin'],

    // Content.
    'posts.publish' => ['owner', 'admin', 'editor'],
    'posts.draft' => ['owner', 'admin', 'editor', 'author'],

    // Media library: everyone who works on posts may look and upload; deleting and watermarks are for editors and up.
    'media.view' => ['owner', 'admin', 'editor', 'author', 'viewer'],
    'media.upload' => ['owner', 'admin', 'editor', 'author'],
    'media.manage' => ['owner', 'admin', 'editor'],

    // Looking, without touching.
    'calendar.view' => ['owner', 'admin', 'editor', 'author', 'viewer', 'client'],
    'analytics.view' => ['owner', 'admin', 'editor', 'author', 'viewer', 'client'],
];
