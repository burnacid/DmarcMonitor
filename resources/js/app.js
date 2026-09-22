import { Chart, LineController, DoughnutController, LineElement, PointElement, ArcElement, LinearScale, CategoryScale, Tooltip, Legend, Filler } from 'chart.js';
import { Passkeys } from '@laravel/passkeys';

Chart.register(LineController, DoughnutController, LineElement, PointElement, ArcElement, LinearScale, CategoryScale, Tooltip, Legend, Filler);

window.Chart = Chart;
window.Passkeys = Passkeys;
