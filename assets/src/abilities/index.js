/**
 * Albert — Abilities (DataViews) admin app entry.
 *
 * Mounts the React app into the admin page root. No CSS is imported here —
 * styles are authored as plain CSS in assets/css/admin-abilities.css and the
 * DataViews/component stylesheets are enqueued separately.
 */
import { mountApp } from '../shared/mountApp';
import AbilitiesApp from './AbilitiesApp';

mountApp( 'albert-abilities-root', <AbilitiesApp /> );
