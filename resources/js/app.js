import { Chart, LineController, DoughnutController, LineElement, PointElement, ArcElement, LinearScale, CategoryScale, Tooltip, Legend, Filler } from 'chart.js';
import { Passkeys } from '@laravel/passkeys';

Chart.register(LineController, DoughnutController, LineElement, PointElement, ArcElement, LinearScale, CategoryScale, Tooltip, Legend, Filler);

window.Chart = Chart;
window.Passkeys = Passkeys;

/*
 * Installable app. Browsers that support installing (Chrome, Edge) fire
 * beforeinstallprompt; it is kept so the "Install app" item in the user menu
 * can show the browser's install dialog later. The menu item listens for
 * pwa-installable to show or hide itself. With wire:navigate this module
 * runs once per full page load, so the saved prompt survives page changes.
 */
let deferredInstallPrompt = null;

const announceInstallable = () => window.dispatchEvent(new CustomEvent('pwa-installable', { detail: window.pwaInstall.available() }));

window.pwaInstall = {
    available: () => deferredInstallPrompt !== null && !window.matchMedia('(display-mode: standalone)').matches,
    async prompt() {
        if (deferredInstallPrompt === null) {
            return;
        }

        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice;
        // A prompt can only be shown once; the browser fires a new event if installing is still possible.
        deferredInstallPrompt = null;
        announceInstallable();
    },
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    announceInstallable();
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    announceInstallable();
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
