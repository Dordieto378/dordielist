import './bootstrap';
import '../css/flags.css';
import { initDoujinImport } from './doujin-import';
import { initAppDialogs } from './app-dialog';
import { initDoujinArtists } from './doujin-artists';
import { initResponsiveNavigation } from './responsive';
import { initReaderProgress } from './reader-progress';

window.initReaderProgress = initReaderProgress;

initAppDialogs();
initDoujinImport();
initDoujinArtists();
initResponsiveNavigation();
