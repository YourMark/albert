/**
 * Albert: Context admin app entry.
 *
 * Mounts the React app into the admin page root. No CSS is imported here,
 * styles are authored as plain CSS in assets/css/admin-context.css and enqueued
 * separately, on top of the shared primitives.
 */
import { mountApp } from '../shared/mountApp';
import ContextApp from './ContextApp';

mountApp( 'albert-context-root', <ContextApp /> );
