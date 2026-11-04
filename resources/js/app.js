import './bootstrap';
import '../css/flags.css';
import { initDoujinImport } from './doujin-import';
import { initAppDialogs } from './app-dialog';
import { initDoujinArtists } from './doujin-artists';
import { initResponsiveNavigation } from './responsive';

initAppDialogs();
initDoujinImport();
initDoujinArtists();
initResponsiveNavigation();
