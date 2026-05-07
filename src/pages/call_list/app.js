
/**
 * Call List - Vue Entry Point
 */

import { createApp } from 'vue'
import App from './App.vue'
import './style.css'

// PrimeVue
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'

// PrimeVue Components
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Toast from 'primevue/toast'
import ProgressSpinner from 'primevue/progressspinner'
import Popover from 'primevue/popover'
import InputText from 'primevue/inputtext';

// PrimeIcons
import 'primeicons/primeicons.css'

const app = createApp(App)

app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            prefix: 'p',
            darkModeSelector: '.dark-mode',
            cssLayer: false
        }
    },
    ripple: false
})

app.use(ToastService)

app.component('DataTable', DataTable)
app.component('Column', Column)
app.component('Toast', Toast)
app.component('ProgressSpinner', ProgressSpinner)
app.component('Popover', Popover)
app.component('InputText', InputText)


app.mount('#ORCA_CALL_LIST')
