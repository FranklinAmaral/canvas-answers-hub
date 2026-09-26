import '@fontsource/poppins/latin-300.css';
import '@fontsource/poppins/latin-400.css';
import '@fontsource/poppins/latin-500.css';
import '@fontsource/poppins/latin-600.css';
import '@fontsource/poppins/latin-700.css';
import 'boxicons/css/boxicons.min.css';
import 'node-waves/dist/waves.min.css';
import 'simplebar/dist/simplebar.min.css';
import '../scss/graderai.scss';

import * as bootstrap from 'bootstrap';
import htmx from 'htmx.org';
import Waves from 'node-waves';
import SimpleBar from 'simplebar';

window.bootstrap = bootstrap;
window.htmx = htmx;
window.SimpleBar = SimpleBar;

const initializeLayout = () => {
    Waves.init();

    const menuButton = document.getElementById('vertical-menu-btn');

    menuButton?.addEventListener('click', () => {
        if (window.innerWidth >= 992) {
            document.body.classList.toggle('vertical-collpsed');
            return;
        }

        document.body.classList.toggle('sidebar-enable');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992) {
            document.body.classList.remove('sidebar-enable');
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLayout, { once: true });
} else {
    initializeLayout();
}
