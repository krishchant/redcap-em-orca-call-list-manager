<script setup>

import { ref, computed, onMounted, watch } from 'vue'
import {FilterMatchMode} from '@primevue/core/api';

let toast = null
const orca = OrcaCallList()

const config = ref(orca.config)
const records = ref([])
const loading = ref(false)
const initialized = ref(false)


const filters = ref({
    checkboxSelections: orca.pageState?.checkboxSelections || {},
    dropdownSelections: {
        ddlfilter: orca.pageState?.dropdownSelections?.ddlfilter || ''
    }
})


const first = ref(0)
const rows = ref(config.value?.showEntriesNumber || 10)


const multiSortMeta = ref([])
const tableFilters = ref({
    global: { value: null, matchMode:FilterMatchMode.CONTAINS }
})

const globalFilterFields = computed(() => {
    const fields = ['recordId']
    if(config.value?.displayFields){
        config.value.displayFields.forEach(f=> fields.push(f.fieldName))
    }
    return fields;
})


const primaryFilterOptions = computed(() => Object.values(config.value?.primaryFilterMetadata || {}))
const legendItems = computed(() => primaryFilterOptions.value.filter(item => item.status))
const filterFieldOptions = computed(() => {
    const ff = config.value?.filterField
    if (!ff?.fieldValues) return []
    return Object.entries(ff.fieldValues).map(([key, label]) => ({ value: key, label }))
})

const hasActiveFilters = computed(() => {
    const hasCheckbox = Object.values(filters.value.checkboxSelections).some(v => v === true)
    const ddVal = filters.value.dropdownSelections?.ddlfilter
    const hasDropdown = ddVal !== '' && ddVal !== undefined && ddVal !== null
    return hasCheckbox || hasDropdown
})

const flattenedRecords = computed(() => {
    return records.value.map(record => {
        const flat = {
            ...record,
            _original: record // Keep original for reference
        }
        // Flatten fields to top level for sorting
        if (record.fields) {
            for (const [fieldName, fieldData] of Object.entries(record.fields)) {
                // use __SORT__ if available
                let value = fieldData?.value ?? ''
                if(fieldName === 'contact_attempts'){
                    flat[`${fieldName}_sort`] = fieldData?.__SORT__ ?? fieldData?.value ?? ''
                }
                else if(fieldData?.__SORT__ !== undefined){
                    flat[`${fieldName}_sort`] = fieldData.__SORT__
                }
                // convert to string for sorting
                else if (Array.isArray(value)) {
                    value = value.join(', ')
                } else if (typeof value === 'object' && value !== null) {
                    value = Object.values(value).join(', ')
                }
                flat[`${fieldName}_sort`] = flat[`${fieldName}_sort`] ?? value
                flat[fieldName] = value
            }
        }
            return flat
    })
})

const initialSortFields = computed(() => {
    return (config.value?.displayFields || [])
        .filter(col => col.sortable && col.sortDirection && col.sortDirection !== 'NONE')
        .sort((a, b) => (a.sortPriority || 999) - (b.sortPriority || 999))
        .map(col => ({ field: col.fieldName, order: col.sortDirection === 'asc' ? 1 : -1 }))
})

watch(initialSortFields, (fields) => {
    if (fields.length) multiSortMeta.value = fields
}, { immediate: true })


function isChecked(key) {
    return filters.value.checkboxSelections[key] === true
}

function toggleCheckbox(key) {
    filters.value.checkboxSelections[key] = !isChecked(key)
}

function getRowClass(record) {
    return record?.primaryFilter?.status || ''
}

function getFieldValue(record, fieldName) {
    return record[fieldName] ?? record.fields?.[fieldName]?.value ?? ''
}

function hasAlert(record, fieldName) {
    return record._original?.fields?.[fieldName]?.alert === true || record.fields?.[fieldName]?.alert === true
}

function isArrayValue(value) {
    return Array.isArray(value) || (typeof value === 'object' && value !== null)
}

function toDisplayArray(value) {
    if (Array.isArray(value)) return value
    if (typeof value === 'object' && value !== null) return Object.values(value)
    return [value]
}

function isEmailField(column) {
    return column.elementValidationType === 'email'
}

function isContactAttemptsColumn(column) {
    return column.fieldName === 'contact_attempts' && column.isVirtual
}

// Debounce helper
let saveTimeout = null
function debouncedSaveState() {
    clearTimeout(saveTimeout)
    saveTimeout = setTimeout(saveState, 500)
}

async function saveState() {
    try {
        await orca.jsmo.ajax('save-page-state', {
            configIndex: config.value?.configIndex ?? 0,
            checkboxSelections: filters.value.checkboxSelections,
            dropdownSelections: filters.value.dropdownSelections
        })
    } catch (e) {
        console.warn('Failed to save state:', e)
    }
}


watch(filters, () => {
    if (initialized.value) debouncedSaveState()
}, { deep: true })

async function fetchData() {
    loading.value = true
    try {
        const primaryFilterIds = Object.entries(filters.value.checkboxSelections)
            .filter(([k, v]) => v === true)
            .map(([k]) => k)

        const response = await orca.jsmo.ajax('get-call-list-data', {
            configIndex: config.value?.configIndex ?? 0,
            filters: {
                primaryFilterIds,
                extraFilterValue: filters.value.dropdownSelections?.ddlfilter || ''
            }
        })

        if (response.success) {
            records.value = response.records || []
            first.value = 0
        } else {
            throw new Error(response.error || 'Failed to fetch data')
        }
    } catch (e) {
        console.error('Fetch error:', e.message)
        // toast?.add({ severity: 'error', summary: 'Error', detail: e.message, life: 5000 })
    } finally {
        loading.value = false
    }
}


async function applyFilters() {
    await fetchData()
}

const contactPopover = ref()
function toggleContactInfo(event) {
    contactPopover.value.toggle(event)
}

onMounted(async () => {
    initialized.value = true
    if (!config.value?.preventEmptySearch || hasActiveFilters.value) {
        await fetchData()
    }
})
</script>

<template>
    <div class="call-list">
        <div v-for="(error, i) in config?.errors || []" :key="i" class="alert alert-danger">
            <strong>Error:</strong> {{ error }}
        </div>

        <div class="call-list-card">
            <div class="call-list-header">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h5 class="mb-0">{{ config?.displayTitle || 'Call List' }}</h5>

                    <div v-if="config?.filterField">
                        <label class="form-label fw-bold mb-1 d-block">{{ config.filterField.fieldLabel }}</label>
                        <select
                            class="form-select w-auto"
                            v-model="filters.dropdownSelections.ddlfilter"
                        >
                            <option value="">--</option>
                            <option v-for="opt in filterFieldOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <hr class="my-1">
                <div class="filter-section">
                    <h6 class="mb-1">Filters</h6>
                    <div class="row">
                        <div class="col-lg-3 col-md-4 col-sm-6 mb-1">
                            <div class="form-check filter-checkbox">
                                <input type="checkbox" class="form-check-input" id="filter_no_value"
                                       :checked="isChecked('no_value')" @change="toggleCheckbox('no_value')">
                                <label class="form-check-label" for="filter_no_value">No Value</label>
                            </div>
                        </div>
                        <div v-for="opt in primaryFilterOptions" :key="opt.key" class="col-lg-3 col-md-4 col-sm-6 mb-1">
                            <div class="form-check filter-checkbox">
                                <input type="checkbox" class="form-check-input" :id="`filter_${opt.key}`"
                                       :checked="isChecked(opt.key)" @change="toggleCheckbox(opt.key)">
                                <label class="form-check-label" :for="`filter_${opt.key}`">{{ opt.label }}</label>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-1">
                <div class="filter-section">
                    <h6 class="mb-1">Legend</h6>
                    <div class="row">
                        <div v-for="item in legendItems" :key="item.key" class="col-md-4 col-sm-6 mb-1">
                            <span class="cl-filter-square" :class="item.status"></span>
                            {{ item.label }}
                        </div>
                    </div>
                </div>

                <div v-if="config?.hasCallbackAlertField" class="mt-2">
                    <i class="pi pi-exclamation-triangle text-danger"></i>
                    <span class="ms-1">Callback date/time has been exceeded</span>
                </div>

                <hr class="my-1">
                <div class="d-flex justify-content-between align-items-center gap-3 mt-1">
                    <InputText v-model="tableFilters.global.value" placeholder="Search anything here"/>
                    <button type="button" class="btn btn-secondary shadow" :disabled="loading" @click="applyFilters">
                        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="pi pi-filter me-1"></i>
                        Apply Filters
                    </button>
                </div>
            </div>

            <div class="call-list-body">
                <div v-if="config?.preventEmptySearch && !hasActiveFilters && records.length === 0" class="alert alert-info">
                    <i class="pi pi-info-circle me-2"></i>
                    Please select filters and click "Apply Filters" to view data.
                </div>

                <DataTable
                    :value="flattenedRecords"
                    :loading="loading"
                    :filters="tableFilters"
                    :globalFilterFileds = "globalFilterFields"
                    :rowClass="getRowClass"
                    :paginator="true"
                    :rows="rows"
                    :rowsPerPageOptions="config?.showEntriesOptions || [10, 25, 50, 100]"
                    :first="first"
                    sortMode="multiple"
                    :multiSortMeta="multiSortMeta"
                    removableSort
                    stripedRows
                    showGridlines
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
                    currentPageReportTemplate="Showing {first} to {last} of {totalRecords} entries"
                    tableStyle="min-width: 50rem"
                    @page="e => { first = e.first; rows = e.rows }"
                >
                    <template #empty>
                        <div class="text-center py-4 text-muted">
                            <i class="pi pi-inbox" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-0">No records found</p>
                        </div>
                    </template>

                    <template #loading>
                        <div class="loading-container">
                            <ProgressSpinner style="width: 40px; height: 40px;" />
                            <p class="mt-2 mb-0">Loading...</p>
                        </div>
                    </template>

                    <Column
                        v-for="col in config?.displayFields || []"
                        :key="col.fieldName"
                        :field="col.fieldName"
                        :sortable="col.sortable"
                        :sortField="col.fieldName +'_sort'"
                    >
                        <template #header>
                            <span>{{ col.label }}</span>
                            <span v-if="isContactAttemptsColumn(col)" class="ms-1 text-primary" style="cursor: help;" @click.stop="toggleContactInfo">
                <i class="pi pi-info-circle"></i>
              </span>
                        </template>

                        <template #body="{ data }">
                            <i v-if="hasAlert(data, col.fieldName)" class="pi pi-exclamation-triangle text-danger me-1"></i>

                            <template v-if="isEmailField(col) && getFieldValue(data, col.fieldName)">
                                <a :href="`mailto:${getFieldValue(data, col.fieldName)}`" class="text-primary">
                                    <i class="pi pi-envelope" style="font-size: 1.25rem;"></i>
                                </a>
                            </template>

                            <template v-else-if="isContactAttemptsColumn(col)">
                                <div v-for="(a, i) in toDisplayArray(getFieldValue(data, col.fieldName))" :key="i" class="mb-1">
                                    <span class="badge bg-secondary fs-6">{{ a }}</span>
                                </div>
                            </template>

                            <template v-else-if="isArrayValue(getFieldValue(data, col.fieldName))">
                                <ul v-if="toDisplayArray(getFieldValue(data, col.fieldName)).length" class="mb-0 ps-3">
                                    <li v-for="(item, i) in toDisplayArray(getFieldValue(data, col.fieldName))" :key="i">{{ item }}</li>
                                </ul>
                            </template>

                            <template v-else>{{ getFieldValue(data, col.fieldName) }}</template>
                        </template>
                    </Column>

                    <Column header="Record Home" style="width: 120px;">
                        <template #body="{ data }">
                            <a :href="data.dashboardUrl" class="btn btn-sm btn-outline-secondary shadow">
                                <i class="pi pi-user-edit me-1"></i>Open
                            </a>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <Popover ref="contactPopover">
            <div class="p-3" style="max-width: 280px;">
                <h6 class="mb-1">Contact Attempts</h6>
                <p class="small text-muted mb-1">24-hour format</p>
                <ul class="mb-0 ps-3" v-if="config?.contactAttempts?.ranges">
                    <li v-for="(range, key) in config.contactAttempts.ranges" :key="key">
                        <strong>{{ key }}</strong>: {{ range.label }}
                    </li>
                </ul>
            </div>
        </Popover>
    </div>
</template>