<template>
  <simple-dialog
    @close="closeDialog"
    :width="400"
  >
    <div :class="{'loader-wrap': this.showLoading}">
      <div :class="{'loader': this.showLoading}"></div>
    </div>
    <div>
      <div class="title">
        <h2>
          {{$t('vehicle.Transfer tracker')}}
        </h2>
      </div>

      <form action="#">
        <div
          class="form-group"
          :class="{ 'has-error': this.$v.form.vehicleFromId.$error }"
        >
          <label class="control-label">{{$t('vehicle.Energy machine where the tracker is installed')}}</label>

          <vue-select
            v-model="form.vehicleFromId"
            :options="vehicleWithActiveServiceList"
            :placeholder="$t('vehicle.Vehicle')"
            labelColumn="name"
            @input="checkAllowedType"
            ref="name"
          >
            <template slot="noResult"><div>{{ $t('common.Not found') }}</div></template>
            <template slot="noOptions"><div>{{ $t('common.Not found') }}</div></template>
          </vue-select>
          <label class="small-text">{{ form.vehicleFromId ? form.vehicleFromId.note : '' }}</label>
        </div>
        <div
          class="form-group"
          :class="{ 'has-error': this.$v.form.vehicleToId.$error }"
        >
          <label class="control-label">{{$t('vehicle.Energy machine where you need to transfer the tracker')}}</label>
          <vue-select
            v-model="form.vehicleToId"
            :options="vehicleWithoutActiveServiceList"
            :placeholder="$t('vehicle.Vehicle')"
            labelColumn="name"
            ref="vehicleToId"
          >
            <template slot="noResult"><div>{{ $t('common.Not found') }}</div></template>
            <template slot="noOptions"><div>{{ $t('common.Not found') }}</div></template>
          </vue-select>
          <label class="small-text">{{ form.vehicleToId ? form.vehicleToId.note : '' }}</label>
        </div>
        <div
          class="form-group"
          :class="{ 'has-error': this.$v.form.transferTime.$error }"
        >
          <label class="control-label">{{$t('vehicle.Transfer date')}}</label>
          <date-picker
            v-model="form.transferTime"
            type="datetime"
            style="width:328px;"
            :lang="langOptions"
            format="DD.MM.YYYY HH:mm:ss"
            confirm
            :not-after="disabledAfter"
            :not-before="disabledBefore"
          ></date-picker>
        </div>

        <button
          v-if="this.isDetectedNonActiveStatus"
          class="btn-primary btn"
          @click.prevent="getConfirmPopup"
          :disabled="!form.vehicleFromId || !form.vehicleToId"
        >
          {{$t('vehicle.To transfer')}}
        </button>
        <button
          v-if="!this.isDetectedNonActiveStatus"
          class="btn-primary btn"
          @click.prevent="prepareStartTransfer"
          :disabled="!form.vehicleFromId || !form.vehicleToId"
        >
          {{$t('vehicle.To transfer')}}
        </button>
        <input
          type="reset"
          class="popup-btn-cancel btn btn-default btn-reset"
          :value="$t('common.Cancel')"
          @click="resetForm"
        >
      </form>
    </div>
    <simple-dialog
      v-if="this.showConfirmPopup"
      @close="closeConfirmPopup"
      :width="400"
    >
      <div style="margin-bottom: 20px;">
        <label class="control-label">{{$t('vehicle.Your subscription status is Error / Reorder. Unreceived data may be lost.')}}</label>
        <label class="control-label">{{$t('vehicle.Do you want to continue transferring the tracker?')}}</label>
      </div>
      <button
        class="btn-primary btn"
        @click.prevent="prepareStartTransfer"
      >
        {{$t('common.Yes')}}
      </button>
      <button
        class="btn btn-default btn-reset"
        @click.prevent="closeConfirmPopup"
      >
        {{$t('common.Cancel')}}
      </button>
    </simple-dialog>
    <simple-dialog
      v-if="this.showFieldWorkList"
      @close="closeFieldWorkListPopup"
      :width="400"
    >
      <div class="title">
        <h2>
          {{$t('vehicle.Transfer error')}}
        </h2>
      </div>
      <label class="control-label">{{$t('vehicle.The vehicle contains tracks tied to field work.')}}</label>
      <label class="control-label">{{$t('vehicle.To transfer, delete the following field works:')}}</label>
      <ul>
        <li class="active" v-for="(item, key) in this.fieldWorksNumbers">
          <router-link :to="{path: '/document/fieldWork/detail?id=' + item.id}" target="_blank">
            {{ item.number }}
          </router-link>
        </li>
      </ul>
      <button
        class="btn btn-primary"
        @click.prevent="closeFieldWorkListPopup"
      >
        {{$t('common.Close')}}
      </button>
    </simple-dialog>
  </simple-dialog>
</template>

<script>
  import VueSelect from '@app/components/BaseComponents/VueSelect2'
  import SimpleDialog from '@app/components/BaseComponents/SimpleDialog'
  import DatePicker from '@app/components/BaseComponents/vue2-datepicker'
  import { required } from 'vuelidate/lib/validators'
  import { mapState, mapMutations, mapActions, mapGetters } from 'vuex'
  import Vue from 'vue'
  import config from '@app/config'
  import * as moment from 'moment-timezone'
  import VueClickoutside from '@app/utils/clickoutside'

  Vue.use(VueClickoutside)

  export default {
    name: 'TrackerTransferPopup',
    components: {
      'simple-dialog': SimpleDialog,
      DatePicker,
      VueSelect,
    },
    props: {},
    data () {
      return {
        form: {
          vehicleFromId: null,
          vehicleToId: null,
          transferTime: new Date(),
        },
        showLoading: false,
        datePickerShortcuts: null,
        disabledAfter: null,
        disabledBefore: null,
        defaultDate: null,
        showConfirmPopup: false,
        showFieldWorkList: false,
        readyToTransfer: false,
        fieldWorksNumbers: [],
        isDetectedNonActiveStatus: false,
      }
    },
    validations: {
      form: {
        vehicleFromId: {
          required,
        },
        vehicleToId: {
          required,
        },
        transferTime: {
          required,
        },
      },
    },
    mounted() {
      const date = new Date()
      this.disabledAfter = new Date(date.getFullYear(), date.getMonth(), date.getDate(), date.getHours(), 0, 0, 0)
      this.disabledBefore = new Date(date.getFullYear(), date.getMonth(), 1)
    },
    created() {
      this.getOptions()
    },
    methods: {
      ...mapActions('vehicleServiceTransfer', ['getVehicleList']),
      getOptions () {
        const params = {
          vehicleFromId: this.vehicleFromId,
          vehicleToId: this.vehicleToId,
          transferTime: this.transferTime,
        }
        this.showLoading = true
        this.getVehicleList(params).then(() => {
          this.showLoading = false
        })
      },
      disabledBeforeStartMonthAndAfterToday(date) {
        return date < this.disabledBefore || date > this.disabledAfter
      },
      closeDialog () {
        this.$emit('close')
      },
      resetForm () {
        this.$emit('close')
      },
      getConfirmPopup () {
        if (this.isDetectedNonActiveStatus) {
          this.showConfirmPopup = true
        }
      },
      closeConfirmPopup () {
        this.showConfirmPopup = false
      },
      closeFieldWorkListPopup () {
        this.showFieldWorkList = false
        this.showLoading = false
      },
      prepareStartTransfer () {
        this.showLoading = true
        this.$http.post('machine/vehicle-service-transfer/check-field-works', this.form).then(response => {
          try {
            if (response.ok === true && response.data.success) {
              this.showFieldWorkList = response.data.isDetected
              this.fieldWorksNumbers = response.data.fieldWorks
              this.readyToTransfer = !response.data.isDetected
            } else {
              console.log(response.data.errors)
            }
          } catch (e) {
            console.log(e)
          } finally {
            if (this.readyToTransfer === true) {
              this.startTransfer()
            }
          }
        })
      },
      startTransfer () {
        this.showConfirmPopup = false
        this.showLoading = true
        this.$http.post('machine/vehicle-service-transfer/transfer-tracker', this.form).then(response => {
          try {
            if (response.ok === true && response.data.success) {
              this.$root.$emit('save')
            } else {
              alert(response.data.errors)
            }
          } catch (e) {
            console.log(e)
          } finally {
            this.showLoading = false
            this.closeDialog()
          }
        })
      },
      datePickerReset() {
        this.form.transferTime = new Date(moment()
          .tz(this.timezone))
      },
      checkAllowedType (vehicle) {
        this.showLoading = true
        this.$http.post('machine/vehicle-service-transfer/check-error-or-restarted-type', vehicle.id).then(response => {
          try {
            if (response.ok === true && response.data.success) {
              this.isDetectedNonActiveStatus = response.data.isDetected
            } else {
              this.isDetectedNonActiveStatus = false
              alert(response.data.errors)
            }
          } catch (e) {
            console.log(e)
          } finally {
            this.showLoading = false
          }
        })
      },
    },
    computed: {
      ...mapState('translations', ['lang']),
      ...mapState('organization', ['timezone']),
      ...mapState('vehicleServiceTransfer', ['vehicleList']),
      langOptions () {
        return this.lang === 'uk' ? config.datePickerUkOptions : this.lang
      },
      vehicleWithActiveServiceList() {
        if (this.vehicleList) {
          return this.vehicleList.activeVehicleList
        }
        return []
      },
      vehicleWithoutActiveServiceList() {
        if (this.vehicleList) {
          return this.vehicleList.inactiveVehicleList
        }
        return []
      },
    },
  }
</script>

<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

<style lang="scss" scoped>
  .title {
    margin-bottom: 30px;
  }

  .form-group {
    margin-bottom: 5px;
  }

  .small-text {
    font-size: 11px;
    color: #8e8e8e;
    font-weight: normal;
  }

</style>

<style lang="scss">
  .table, .td, .th {
    border: 1px solid #eef2f4;
  }

  .multiselect, .multiselect__single {
    font-size: 13px;
  }

  .multiselect {
    min-height: 35px;
  }

  .multiselect__tags {
    min-height: 35px;
    max-height: 35px;
    font-size: 13px;
  }

  .multiselect__select {
    padding: 9px 8px;
  }

  .form-group {
    .select2-container {
      width: 100% !important;
    }
  }
</style>