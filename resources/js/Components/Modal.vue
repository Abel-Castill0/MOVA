<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Reescrito con checklist de accesibilidad explícito (no solo "focus trap"):
 * role="dialog" + aria-modal + aria-labelledby opcional; trampa de foco real
 * (Tab/Shift+Tab cicla dentro); foco restaurado al elemento que abrió el
 * modal al cerrarse; Escape cierra; bloqueo de scroll del body; clic en el
 * overlay no interactúa con el contenido de detrás (ya lo hacía, se
 * conserva). En móvil se convierte en hoja inferior (curva de iOS); desde
 * `sm:` vuelve al diálogo centrado de siempre.
 *
 * API sin cambios respecto del Modal anterior — mismos props (`show`,
 * `maxWidth`, `closeable`) y mismo evento `close` que ya usan sus 6
 * call-sites reales (inventariado antes de tocar esto). `titleId` es nuevo
 * y opcional: si el caller le pasa el id de su propio <h2>, el modal queda
 * con nombre accesible real; si no lo pasa (ningún caller lo hace todavía),
 * se degrada exactamente al estado de accesibilidad de antes — nunca peor.
 */
const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    titleId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['close']);

const panel = ref(null);
let lastFocusedElement = null;

const FOCUSABLE_SELECTOR =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusableElements() {
    if (!panel.value) return [];
    return Array.from(panel.value.querySelectorAll(FOCUSABLE_SELECTOR));
}

watch(
    () => props.show,
    (show) => {
        if (show) {
            document.body.style.overflow = 'hidden';
            lastFocusedElement = document.activeElement;
            nextTick(() => {
                const [first] = focusableElements();
                (first ?? panel.value)?.focus();
            });
        } else {
            document.body.style.overflow = null;
            // Restaura el foco a quien abrió el modal — sin esto, tras
            // cerrar, el foco del teclado queda perdido en el <body>.
            lastFocusedElement?.focus?.();
            lastFocusedElement = null;
        }
    }
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

function onKeydown(e) {
    if (!props.show) return;

    if (e.key === 'Escape') {
        close();
        return;
    }

    if (e.key === 'Tab') {
        const elements = focusableElements();
        if (elements.length === 0) {
            e.preventDefault();
            return;
        }
        const first = elements[0];
        const last = elements[elements.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = null;
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '4xl': 'sm:max-w-4xl',
        '7xl': 'sm:max-w-7xl',
    }[props.maxWidth];
});
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-ui">
            <div v-show="show" class="fixed inset-0 overflow-y-auto z-50 sm:px-4 sm:py-6" scroll-region>
                <Transition
                    enter-active-class="ease-out-expo duration-ui"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-out-expo duration-ui"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 bg-black/40" @click="close" />
                </Transition>

                <!-- Base (< sm): hoja inferior, curva estilo iOS.
                     sm+: vuelve al diálogo centrado de siempre. -->
                <Transition
                    enter-active-class="ease-sheet duration-ui sm:ease-out-expo"
                    enter-from-class="translate-y-full sm:translate-y-4 sm:scale-95 opacity-0 sm:opacity-0"
                    enter-to-class="translate-y-0 sm:scale-100 opacity-100"
                    leave-active-class="ease-sheet duration-ui sm:ease-out-expo"
                    leave-from-class="translate-y-0 sm:scale-100 opacity-100"
                    leave-to-class="translate-y-full sm:translate-y-4 sm:scale-95 opacity-0 sm:opacity-0"
                >
                    <!--
                      `sm:relative`, NUNCA `sm:static`.

                      El backdrop hermano es `fixed` (posicionado). Si en
                      escritorio el panel vuelve a `static` queda SIN posicionar,
                      y el orden de pintado de CSS coloca los descendientes
                      posicionados con `z-index:auto` (paso 8) por ENCIMA del
                      contenido no posicionado (pasos 4 y 7). Resultado: el
                      backdrop se pinta sobre el panel e intercepta los clics.

                      Verificado en navegador real, no deducido del CSS: a
                      1440x1000, un clic sobre "Revertir y descontar" CERRABA el
                      modal en vez de activar el botón, porque el evento llegaba
                      al backdrop. En móvil no ocurría porque ahí el panel sigue
                      siendo `fixed`. Curiosamente `document.elementFromPoint()`
                      SÍ devolvía el botón, así que el hit-test sintético no
                      bastaba para verlo — solo el clic real.

                      `relative` sin offsets ocupa exactamente el mismo espacio
                      que `static`, así que el layout no cambia: solo convierte el
                      panel en posicionado, y al ser el hermano POSTERIOR gana por
                      orden de árbol (ambos con `z-index:auto`).
                    -->
                    <div
                        v-show="show"
                        ref="panel"
                        role="dialog"
                        aria-modal="true"
                        :aria-labelledby="titleId ?? undefined"
                        tabindex="-1"
                        class="fixed inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto bg-surface rounded-t-elevated shadow-elevation-3 outline-none pb-[env(safe-area-inset-bottom)] sm:relative sm:mb-6 sm:mx-auto sm:max-h-none sm:rounded-elevated sm:overflow-visible sm:pb-0 sm:w-full"
                        :class="maxWidthClass"
                        @click.stop
                    >
                        <slot v-if="show" />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
