<template>
  <div class="vehicle-service-transfer">
    <div :class="{'loader-wrap': this.showLoading}">
      <div :class="{'loader': this.showLoading}"></div>
    </div>
    <div class="vehicle-service-transfer__actions">
      <div class="buttons-wrapper">
        <button
          class="btn btn-add btn-add-field"
          @click="showTransferTrackerPopup = !showTransferTrackerPopup"
        >{{ $t('vehicle.Transfer tracker') }}
        </button>
        <button
          class="btn btn-add btn-add-field"
          @click="openMountPopup"
        >
          {{ $t('vehicle.Installation') }}
        </button>
        <button
          class="btn btn-add btn-add-field"
          @click="openUnMountPopup"
        >
          {{ $t('vehicle.Removing') }}
        </button>
      </div>
      <date-picker
        v-model="range"
        type="date"
        style="display: flex;right: 1%;float: right;justify-content: flex-end;"
        range
        :range-separator="'-'"
        confirm
        :lang="langOptions"
        :shortcuts="datePickerShortcuts"
        format="DD.MM.YYYY"
      ></date-picker>
    </div>
    <div class="vehicle-service-transfer__table-wrap scroll-body--new">
      <transfer-table
        :transfer-data="this.transferData"
        :range-date-filter="this.range"
      />

      <simple-dialog v-if="actionPopupType" @close="actionPopupType = null" containerClass="action-popup" :width="500">
        <tracker-action-popup @close="actionPopupType = null" :actionType="actionPopupType"></tracker-action-popup>
      </simple-dialog>

      <transfer-popup
        v-if="showTransferTrackerPopup"
        :transfer-data="this.transferData"
        @close="showTransferTrackerPopup = false"
      />
    </div>
  </div>
</template>

<script>
import TrackerTransferPopup from '@app/components/trackerTransfer/TrackerTransferPopup'
import TrackerTransferTable from '@app/components/trackerTransfer/TrackerTransferTable'
import DatePicker from '@app/components/BaseComponents/vue2-datepicker'
import SimpleDialog from '@app/components/BaseComponents/SimpleDialog'
import TrackerActionPopup from './TrackerActionPopup'
import config from '@app/config'
import * as moment from 'moment-timezone'
import { mapState } from 'vuex'
import TrackerTransferActionType from '@app/types/trackerTransfer/TrackerTransferActionType'

export default {
  name: 'TrackerTransfer',
  components: {
    'transfer-popup': TrackerTransferPopup,
    'transfer-table': TrackerTransferTable,
    DatePicker,
    TrackerActionPopup,
    SimpleDialog,
  },
  created() {
    this.datePickerShortcuts = [
      {
        text: this.$t('filter.Interval-Today'),
        start: new Date(moment()
          .hours(0)
          .minutes(0)
          .seconds(0)
          .tz(this.timezone)
          .format()),
        end: new Date(moment()
          .hours(23)
          .minutes(59)
          .seconds(59)
          .tz(this.timezone)
          .format()),
      },
      {
        text: this.$t('filter.Interval-Yesterday'),
        start: new Date(moment()
          .add(-1, 'days')
          .hours(0)
          .minutes(0)
          .seconds(0)
          .tz(this.timezone)
          .format()),
        end: new Date(moment()
          .add(-1, 'days')
          .hours(23)
          .minutes(59)
          .seconds(59)
          .tz(this.timezone)
          .format()),
      },
      {
        text: this.$t('filter.Interval-Week'),
        start: new Date(moment()
          .add(-7, 'days')
          .hours(0)
          .minutes(0)
          .seconds(0)
          .tz(this.timezone)
          .format()),
        end: new Date(moment()
          .hours(23)
          .minutes(59)
          .seconds(59)
          .tz(this.timezone)
          .format()),
      },
      {
        text: this.$t('filter.Interval-Month'),
        start: new Date(moment()
          .add(-30, 'days')
          .hours(0)
          .minutes(0)
          .seconds(0)
          .tz(this.timezone)
          .format()),
        end: new Date(moment()
          .hours(23)
          .minutes(59)
          .seconds(59)
          .tz(this.timezone)
          .format()),
      },
    ]
  },
  computed: {
    ...mapState('vehicleServiceTransfer', ['transferData']),
    ...mapState('translations', ['lang']),
    ...mapState('organization', ['timezone']),
    langOptions() {
      return this.lang === 'uk' ? config.datePickerUkOptions : this.lang
    },
  },
  data() {
    return {
      showLoading: false,
      landPlots: [],
      showTransferTrackerPopup: false,
      showTable: false,
      successUpdatedCount: 0,
      errorMessage: null,
      showErrorMessage: false,
      allCheckbox: true,
      resetDateFilter: false,
      range: [new Date(Date.now() - 604800000), new Date()],
      actionPopupType: null,
      datePickerShortcuts: null,
    }
  },
  methods: {
    openMountPopup() {
      this.actionPopupType = TrackerTransferActionType.TYPE_MOUNT
    },
    openUnMountPopup() {
      this.actionPopupType = TrackerTransferActionType.TYPE_UNMOUNT
    },
  },
}
</script>

<style lang="scss" scoped>
.vehicle-service-transfer {
  height: 100%;
  overflow: hidden;

  &__table-wrap {
    height: calc(100% - 59px);
  }
}

.vehicle-service-transfer__actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}

.btn {
    margin: 0 4px;
}

  .buttons-wrapper {
    margin-left: 10px;
  }
</style>

<style lang="scss">
  body > .select2-container {
    z-index: 10000;
  }

  .action-popup {
    padding-top: 18px;
  }
</style>
