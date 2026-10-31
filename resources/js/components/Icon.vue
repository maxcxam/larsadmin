<script>
import { h } from 'vue'
import IconAlert from './icons/IconAlert.vue'
import IconArrowDown from './icons/IconArrowDown.vue'
import IconArrowUp from './icons/IconArrowUp.vue'
import IconBell from './icons/IconBell.vue'
import IconCheck from './icons/IconCheck.vue'
import IconChevronDown from './icons/IconChevronDown.vue'
import IconChevronLeft from './icons/IconChevronLeft.vue'
import IconChevronRight from './icons/IconChevronRight.vue'
import IconDash from './icons/IconDash.vue'
import IconDatabase from './icons/IconDatabase.vue'
import IconDeviceDesktop from './icons/IconDeviceDesktop.vue'
import IconDownload from './icons/IconDownload.vue'
import IconEye from './icons/IconEye.vue'
import IconFile from './icons/IconFile.vue'
import IconGlobe from './icons/IconGlobe.vue'
import IconHistory from './icons/IconHistory.vue'
import IconHome from './icons/IconHome.vue'
import IconKebab from './icons/IconKebab.vue'
import IconLock from './icons/IconLock.vue'
import IconMenu from './icons/IconMenu.vue'
import IconMoon from './icons/IconMoon.vue'
import IconPencil from './icons/IconPencil.vue'
import IconPlus from './icons/IconPlus.vue'
import IconSearch from './icons/IconSearch.vue'
import IconSignOut from './icons/IconSignOut.vue'
import IconSun from './icons/IconSun.vue'
import IconTrash from './icons/IconTrash.vue'
import IconUpload from './icons/IconUpload.vue'
import IconX from './icons/IconX.vue'

// Public icon component of the SDK (design 1.5 §5.5): <Icon name="pencil" />. Names are an allow-list
// (Icon.NAMES); an unknown name renders nothing. Icons are decorative (aria-hidden): give the control a label.
const ICONS = {
    'alert': IconAlert,
    'arrow-down': IconArrowDown,
    'arrow-up': IconArrowUp,
    'bell': IconBell,
    'check': IconCheck,
    'chevron-down': IconChevronDown,
    'chevron-left': IconChevronLeft,
    'chevron-right': IconChevronRight,
    'dash': IconDash,
    'database': IconDatabase,
    'device-desktop': IconDeviceDesktop,
    'download': IconDownload,
    'eye': IconEye,
    'file': IconFile,
    'globe': IconGlobe,
    'history': IconHistory,
    'home': IconHome,
    'kebab': IconKebab,
    'lock': IconLock,
    'menu': IconMenu,
    'moon': IconMoon,
    'pencil': IconPencil,
    'plus': IconPlus,
    'search': IconSearch,
    'sign-out': IconSignOut,
    'sun': IconSun,
    'trash': IconTrash,
    'upload': IconUpload,
    'x': IconX,
}

const NAMES = Object.freeze(Object.keys(ICONS))

export default {
    name: 'LarsadminIcon',
    NAMES,
    props: {
        name: { type: String, required: true },
    },
    setup(props) {
        return () => {
            const icon = Object.hasOwn(ICONS, props.name) ? ICONS[props.name] : null

            if (!icon && import.meta.env?.DEV) console.warn(`[larsadmin] Unknown icon "${props.name}". Use one of Icon.NAMES.`)

            return icon ? h(icon) : null
        }
    },
}
</script>
