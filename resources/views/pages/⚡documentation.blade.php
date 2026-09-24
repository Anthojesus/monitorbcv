<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentación')] class extends Component
{
}; ?>

<section
    class="flex w-full flex-col gap-6"
    x-data="{
        q: '',
        active: window.location.hash.replace('#', '') || 'estado',
        visible(haystack) {
            const needle = this.q.trim().toLowerCase();
            return needle === '' || haystack.toLowerCase().includes(needle);
        },
        go(id) {
            this.active = id;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            history.replaceState(null, '', '#' + id);
        }
    }"
    x-init="
        const ids = ['estado','motivos','fiabilidad','latencia','tiempo-total','certificado','headers','servidor','health','sondas','despliegue','graficos','comandos','roles','guion'];
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) active = entry.target.id;
            });
        }, { rootMargin: '-20% 0px -65% 0px', threshold: 0.1 });
        ids.forEach((id) => {
            const node = document.getElementById(id);
            if (node) observer.observe(node);
        });
    "
>
    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 px-6 py-6 text-white shadow-xl dark:bg-black/40 sm:px-8">
        <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-sky-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 left-1/3 size-56 rounded-full bg-emerald-400/10 blur-3xl"></div>
        <div class="relative max-w-3xl space-y-3">
            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium tracking-wide uppercase">
                <span class="size-2 rounded-full bg-sky-400"></span>
                Guía del operador
            </span>
            <flux:heading size="xl" class="text-white">Documentación de métricas</flux:heading>
            <p class="text-sm leading-6 text-zinc-300">
                Qué significa cada valor del Centro de Control, la lista de sitios y la ficha de un destino.
                Las métricas describen el último sondeo HTTPS; no sustituyen tu criterio.
            </p>
            <div class="max-w-md">
                <flux:input
                    x-model="q"
                    type="search"
                    placeholder="Buscar: UP, grado, TTFB, sudo, DNS…"
                    icon="magnifying-glass"
                />
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('estado')">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-emerald-500 px-2 py-0.5 text-xs font-bold text-white">UP</span>
                <span class="inline-flex items-center rounded-md bg-rose-500 px-2 py-0.5 text-xs font-bold text-white">DOWN</span>
            </div>
            <div class="mt-2 text-sm font-medium">Estado del sitio</div>
            <p class="mt-1 text-xs text-zinc-500">Disponibilidad del último sondeo, no seguridad.</p>
        </button>
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('headers')">
            <flux:badge color="zinc">Grado A–F</flux:badge>
            <div class="mt-2 text-sm font-medium">Cabeceras de seguridad</div>
            <p class="mt-1 text-xs text-zinc-500">HSTS, CSP y el significado del guion.</p>
        </button>
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('fiabilidad')">
            <flux:badge color="lime">Estable</flux:badge>
            <div class="mt-2 text-sm font-medium">Fiabilidad</div>
            <p class="mt-1 text-xs text-zinc-500">Porcentaje OK de la ventana reciente.</p>
        </button>
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('sondas')">
            <flux:badge color="sky">Exterior</flux:badge>
            <div class="mt-2 text-sm font-medium">API interior y exterior</div>
            <p class="mt-1 text-xs text-zinc-500">Quién abre la URL y la regla de crisis.</p>
        </button>
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('despliegue')">
            <flux:badge color="zinc">VPS</flux:badge>
            <div class="mt-2 text-sm font-medium">Despliegue de contingencia</div>
            <p class="mt-1 text-xs text-zinc-500">Paso a paso para reinstalar la sonda exterior.</p>
        </button>
        <button type="button" class="rounded-xl border border-zinc-200 bg-white p-4 text-start transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10" @click="go('comandos')">
            <flux:badge color="sky">SSH</flux:badge>
            <div class="mt-2 text-sm font-medium">Acciones de servidor</div>
            <p class="mt-1 text-xs text-zinc-500">Consultas, cambios, sudo y aliases.</p>
        </button>
    </div>

    <div class="flex flex-col gap-6 lg:flex-row">
        <nav class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5 lg:sticky lg:top-6 lg:h-fit lg:w-64 lg:shrink-0">
            <flux:heading size="sm">En esta página</flux:heading>
            <div class="mt-3 flex flex-col gap-0.5">
                <template x-for="item in [
                    ['estado', 'Estado UP / DOWN'],
                    ['motivos', 'Motivos del chequeo'],
                    ['fiabilidad', 'Fiabilidad'],
                    ['latencia', 'Latencia de red'],
                    ['tiempo-total', 'Tiempo total y TTFB'],
                    ['certificado', 'Certificado TLS'],
                    ['headers', 'Headers de seguridad'],
                    ['servidor', 'Servidor web'],
                    ['health', 'Web services'],
                    ['sondas', 'API interior y exterior'],
                    ['despliegue', 'Despliegue VPS'],
                    ['graficos', 'Gráficos'],
                    ['comandos', 'Acciones de servidor'],
                    ['roles', 'Roles y ajustes'],
                    ['guion', 'El guion (—)'],
                ]" :key="item[0]">
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-start text-sm transition"
                        :class="active === item[0]
                            ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-white/10 dark:text-white'
                            : 'text-zinc-600 hover:bg-white/70 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white'"
                        @click="go(item[0])"
                        x-text="item[1]"
                    ></button>
                </template>
            </div>
        </nav>

        <div class="min-w-0 flex-1 space-y-5">
            <p x-show="q.trim() !== ''" class="text-sm text-zinc-500" x-cloak>Mostrando secciones que coinciden con la búsqueda.</p>

            <article id="estado" x-show="visible('estado up down pendiente disponibilidad criterio http health 500 responde')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Estado UP / DOWN</flux:heading>
                        <span class="inline-flex items-center rounded-md bg-emerald-500 px-2 py-0.5 text-xs font-bold text-white">UP</span>
                        <span class="inline-flex items-center rounded-md bg-rose-500 px-2 py-0.5 text-xs font-bold text-white">DOWN</span>
                        <span class="inline-flex items-center rounded-md bg-zinc-500 px-2 py-0.5 text-xs font-bold text-white">Pendiente</span>
                    </div>
                    <flux:text>
                        Es el resultado del último sondeo. <strong>UP</strong> significa que el destino cumplió la regla configurada
                        (código HTTP esperado, palabra clave o health JSON). <strong>DOWN</strong> significa que falló esa regla o no se pudo completar el chequeo.
                        <strong>Pendiente</strong> aparece cuando todavía no hay ningún sondeo.
                    </flux:text>
                    <flux:callout icon="information-circle">
                        Un sitio puede estar UP y aun así tener un certificado por vencer o un grado de cabeceras bajo. UP/DOWN no mide seguridad: solo disponibilidad.
                    </flux:callout>
                    <flux:text>
                        En sitios HTTP puedes elegir dos criterios: <em>solo códigos HTTP esperados</em> (por ejemplo 200, 301)
                        o <em>el servidor responde</em> (cualquier código HTTP cuenta como alcanzable).
                        Un badge <strong>UP 500</strong> con motivo «El servidor responde» quiere decir: el monitor llegó al host y recibió un status; no que la página esté bien.
                        Un 5xx es fallo de la aplicación, no un corte de red. Si ese error debe ser DOWN, cambia el criterio a códigos esperados.
                        Los web services (`/health`) se marcan DOWN si el JSON global o una dependencia está DOWN.
                    </flux:text>
                </flux:card>
            </article>

            <article id="motivos" x-show="visible('motivos historial ok fail dns tcp tls timeout health palabra clave')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Motivos del chequeo</flux:heading>
                    <flux:text>El historial muestra por qué se marcó OK o FAIL. Los textos más frecuentes:</flux:text>
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                        <table class="w-full min-w-[36rem] text-sm">
                            <thead class="bg-zinc-50 text-left dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2.5 font-medium">Motivo</th>
                                    <th class="px-3 py-2.5 font-medium">Significado</th>
                                </tr>
                            </thead>
                            <tbody class="align-top text-zinc-600 dark:text-zinc-300">
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Respuesta válida</td><td class="px-3 py-2.5">El sondeo cumplió la regla. El destino se considera UP.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">El servidor responde</td><td class="px-3 py-2.5">El host contestó (puede ser 403, 500, etc.). El verde es “máquina alcanzable”, no “página bien”. Un 5xx es fallo de la app. Cambia a códigos esperados si eso debe ser DOWN.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Código HTTP fuera de lo esperado</td><td class="px-3 py-2.5">Llegó una respuesta, pero el status no coincide con los códigos aceptados.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">No resolvió el DNS</td><td class="px-3 py-2.5">El nombre del host no se tradujo a una IP.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">No estableció TCP</td><td class="px-3 py-2.5">Se conoció la IP, pero no se abrió el puerto (firewall, servicio caído, red).</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Fallo de certificado TLS</td><td class="px-3 py-2.5">El certificado está vencido, el hostname no coincide o la cadena no es de confianza. Una CA interna sola no marca DOWN: el sistema reintenta.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Se agotó el tiempo de espera</td><td class="px-3 py-2.5">El destino no respondió dentro del timeout del sitio.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Sin respuesta HTTP</td><td class="px-3 py-2.5">La conexión avanzó, pero no hubo status HTTP usable.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">No apareció la palabra clave</td><td class="px-3 py-2.5">El cuerpo no contiene el texto que configuraste (solo sitios HTTP).</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">El health global está DOWN</td><td class="px-3 py-2.5">El JSON del web service reporta el servicio caído.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">Una dependencia del health está DOWN</td><td class="px-3 py-2.5">LDAP, base de datos u otro check interno falló.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">El JSON de health no es válido</td><td class="px-3 py-2.5">La URL respondió, pero el cuerpo no es un health reconocible.</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-white">No se está monitoreando</td><td class="px-3 py-2.5">La sonda Interior o Exterior está caída o apagada. No es una falla del portal. El último UP/DOWN se conserva.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </flux:card>
            </article>

            <article id="fiabilidad" x-show="visible('fiabilidad estable inestable intermitente porcentaje ok racha')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Fiabilidad</flux:heading>
                    <flux:text>
                        Porcentaje de sondeos OK en la ventana reciente (los últimos chequeos guardados, no “desde siempre”).
                        Un sitio puede estar UP ahora y mostrar menos de 100% si falló minutos atrás.
                    </flux:text>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <flux:badge color="lime">Estable</flux:badge>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">≥ 95%. El destino se comporta de forma continua.</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <flux:badge color="amber">Inestable</flux:badge>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">70–94%. Hay caídas intermitentes en la ventana.</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <flux:badge color="rose">Intermitente</flux:badge>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">&lt; 70%. Falló una parte importante de los chequeos.</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <flux:badge color="zinc">Sin historial</flux:badge>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Todavía no hay muestras.</p>
                        </div>
                    </div>
                    <flux:text>
                        El texto “X de Y OK” es el conteo crudo. La racha de aciertos indica cuántos OK seguidos hay desde el último fallo.
                    </flux:text>
                </flux:card>
            </article>

            <article id="latencia" x-show="visible('latencia red dns tcp rápida lenta ms')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Latencia de red</flux:heading>
                    <flux:text>
                        Suma de <strong>DNS + TCP</strong>: el tiempo de resolver el nombre y abrir la conexión al puerto.
                        No incluye TLS, ni la aplicación, ni la descarga de la página.
                    </flux:text>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-emerald-500/10 p-3">
                            <div class="text-sm font-medium">Red rápida</div>
                            <div class="mt-1 font-mono text-lg">≤ 100 ms</div>
                        </div>
                        <div class="rounded-lg bg-amber-500/10 p-3">
                            <div class="text-sm font-medium">Red aceptable</div>
                            <div class="mt-1 font-mono text-lg">≤ 250 ms</div>
                        </div>
                        <div class="rounded-lg bg-rose-500/10 p-3">
                            <div class="text-sm font-medium">Red lenta</div>
                            <div class="mt-1 font-mono text-lg">&gt; 250 ms</div>
                        </div>
                    </div>
                    <flux:text>
                        Si un sitio está lento en “tiempo total” pero la red es rápida, el retraso suele estar en TLS o en la aplicación (TomEE, PHP, base de datos).
                    </flux:text>
                </flux:card>
            </article>

            <article id="tiempo-total" x-show="visible('tiempo total ttfb gráfico solo red aplicación')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Tiempo total y TTFB</flux:heading>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <div class="text-sm font-medium">Tiempo total</div>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">DNS + TCP + TLS + espera de la aplicación + primera respuesta.</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <div class="text-sm font-medium">TTFB</div>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Hasta el primer byte HTTP. Incluye la app (time to first byte).</p>
                        </div>
                    </div>
                    <flux:text>
                        En el gráfico puedes cambiar entre <em>Tiempo total</em> y <em>Solo red</em> para ver si el pico es de infraestructura de red o del servicio.
                    </flux:text>
                </flux:card>
            </article>

            <article id="certificado" x-show="visible('certificado tls ssl vencimiento ca corporativa días')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Certificado TLS</flux:heading>
                        <flux:badge color="amber">21 d o menos</flux:badge>
                    </div>
                    <flux:text>
                        Muestra los <strong>días restantes</strong> hasta el vencimiento del certificado que presentó el servidor.
                        En el Centro de Control, menos de 15 días se marcan en ámbar; menos de 7 es crítico. Un sitio UP con TTFB alto o 5xx pasa a Degradado.
                    </flux:text>
                    <flux:callout icon="shield-check" variant="warning">
                        <strong>Sin dato TLS</strong> aparece si el chequeo no llegó a negociar HTTPS. Una CA corporativa que no está en el almacén público no marca el sitio DOWN por sí sola; un certificado vencido o con hostname incorrecto sí es incidencia.
                    </flux:callout>
                </flux:card>
            </article>

            <article id="headers" x-show="visible('headers seguridad grado score hsts csp cabeceras a b c d f')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Headers de seguridad (grado)</flux:heading>
                    <flux:text>
                        No es el estado UP/DOWN. Evalúa si la respuesta HTTPS trae cabeceras que endurecen el navegador.
                        Cada cabecera presente suma puntos (máximo 100).
                    </flux:text>
                    <div class="flex flex-wrap gap-2">
                        <flux:badge color="lime">A ≥ 90</flux:badge>
                        <flux:badge color="lime">B ≥ 70</flux:badge>
                        <flux:badge color="amber">C ≥ 50</flux:badge>
                        <flux:badge color="amber">D ≥ 30</flux:badge>
                        <flux:badge color="zinc">F &lt; 30</flux:badge>
                        <flux:badge color="zinc">— sin dato</flux:badge>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                        <table class="w-full min-w-[36rem] text-sm">
                            <thead class="bg-zinc-50 text-left dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2.5 font-medium">Cabecera</th>
                                    <th class="px-3 py-2.5 font-medium">Para qué sirve</th>
                                    <th class="px-3 py-2.5 font-medium">Puntos</th>
                                </tr>
                            </thead>
                            <tbody class="align-top text-zinc-600 dark:text-zinc-300">
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">Strict-Transport-Security</td><td class="px-3 py-2.5">Obliga al navegador a usar HTTPS (HSTS).</td><td class="px-3 py-2.5 font-mono">25</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">Content-Security-Policy</td><td class="px-3 py-2.5">Limita scripts, estilos y orígenes que la página puede cargar.</td><td class="px-3 py-2.5 font-mono">25</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">X-Frame-Options</td><td class="px-3 py-2.5">Evita que el sitio se embeba en un iframe (clickjacking).</td><td class="px-3 py-2.5 font-mono">15</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">X-Content-Type-Options</td><td class="px-3 py-2.5">Impide que el navegador “adivine” el tipo de archivo.</td><td class="px-3 py-2.5 font-mono">15</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">Referrer-Policy</td><td class="px-3 py-2.5">Controla qué URL se envía al salir del sitio.</td><td class="px-3 py-2.5 font-mono">10</td></tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5"><td class="px-3 py-2.5 font-mono text-xs">Permissions-Policy</td><td class="px-3 py-2.5">Restringe cámara, micrófono y otras APIs del navegador.</td><td class="px-3 py-2.5 font-mono">10</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <flux:callout icon="information-circle">
                        En la ficha verás también <strong>Score X / 100</strong>. Un portal puede estar UP y tener grado F: responde bien, pero no manda estas protecciones.
                        El grado <strong>—</strong> no es una F: ese sondeo no trajo el análisis.
                    </flux:callout>
                </flux:card>
            </article>

            <article id="servidor" x-show="visible('servidor web nginx apache tomcat iis server powered via')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Servidor web</flux:heading>
                    <flux:text>
                        Familia detectada a partir de las cabeceras <span class="font-mono">Server</span>, <span class="font-mono">X-Powered-By</span> o <span class="font-mono">Via</span>
                        (Nginx, Apache, Tomcat, IIS, etc.). “Sin dato” significa que el sondeo no trajo esa cabecera; muchos reverse proxy la ocultan a propósito.
                    </flux:text>
                </flux:card>
            </article>

            <article id="health" x-show="visible('health web service dependencias ldap base de datos actuator json envuelto success data result payload')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Web services y dependencias</flux:heading>
                        <flux:badge color="sky">Web service</flux:badge>
                    </div>
                    <flux:text>
                        Los destinos tipo <strong>Web service</strong> (URL con <span class="font-mono">/health</span> o Actuator) parsean el JSON de salud.
                        El badge global (UP/DOWN) es el status del servicio. Las tarjetas (LDAP, base de datos, TomEE, etc.) son checks individuales del payload.
                    </flux:text>
                    <flux:text>
                        Se reconocen MicroProfile (<span class="font-mono">checks</span>), Spring Actuator (<span class="font-mono">components</span>),
                        el genérico en raíz (<span class="font-mono">status</span>, <span class="font-mono">healthy</span> o <span class="font-mono">state</span>)
                        y el mismo contenido <strong>envuelto</strong> en <span class="font-mono">data</span>, <span class="font-mono">result</span> o <span class="font-mono">payload</span>.
                        Si no hay status, <span class="font-mono">success: true</span> cuenta como UP. Valores válidos de status: UP, OK, ALIVE, HEALTHY, PASS.
                    </flux:text>
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        Si una dependencia está DOWN, el sitio puede marcarse DOWN aunque Nginx responda 200. El problema está detrás del balanceador, no necesariamente en el HTTP de frente.
                    </flux:callout>
                </flux:card>
            </article>

            <article id="sondas" x-show="visible('sonda api interior exterior fastapi vps bearer token probe_unavailable crisis vpn uvicorn health checks logs cron probe.py systemd 8100 semaforo semáforo jsonl')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">API interior y API exterior</flux:heading>
                        <flux:badge color="zinc">Interior</flux:badge>
                        <flux:badge color="sky">Exterior</flux:badge>
                    </div>
                    <flux:text>
                        Laravel <strong>no abre las URLs</strong>. Solo decide qué sondear y desde dónde. FastAPI es quien sale a la red, mide y devuelve un JSON.
                        El código Python es el mismo (<span class="font-mono">services/monitor</span>); cambian el host, el token y la red desde la que sale.
                    </flux:text>
                    <flux:text>
                        Un destino tiene un solo origen (<span class="font-mono">internal</span> o <span class="font-mono">external</span>).
                        Si el mismo portal importa en ambos mundos, se crean <strong>dos destinos</strong> (por ejemplo BCV OFICIAL INTRA y BCV OFICIAL EXTRA).
                    </flux:text>

                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-zinc-50 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-medium"> </th>
                                    <th class="px-3 py-2.5 font-medium">Interior</th>
                                    <th class="px-3 py-2.5 font-medium">Exterior</th>
                                </tr>
                            </thead>
                            <tbody class="text-zinc-700 dark:text-zinc-200">
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-medium">Dónde corre</td>
                                    <td class="px-3 py-2.5">Mismo host que Laravel, <span class="font-mono">127.0.0.1:8100</span> (systemd <span class="font-mono">monitorbcv-internal</span>)</td>
                                    <td class="px-3 py-2.5">VPS público, <span class="font-mono">https://monitor.tudrgroup.com</span></td>
                                </tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-medium">Red</td>
                                    <td class="px-3 py-2.5">LAN del BCV (<span class="font-mono">.intra.</span>, <span class="font-mono">.extra.</span>, IPs privadas)</td>
                                    <td class="px-3 py-2.5">Internet, sin VPN BCV. Vista del ciudadano.</td>
                                </tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-medium">Token en Laravel</td>
                                    <td class="px-3 py-2.5"><span class="font-mono">MONITOR_API_INTERNAL_TOKEN</span></td>
                                    <td class="px-3 py-2.5"><span class="font-mono">MONITOR_API_EXTERNAL_TOKEN</span></td>
                                </tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-medium">Token en Python</td>
                                    <td class="px-3 py-2.5" colspan="2">En cada host se llama <span class="font-mono">MONITOR_API_TOKEN</span> y debe coincidir solo con el origen de ese host. No los mezcle.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <flux:heading size="sm">Rutas de FastAPI</flux:heading>
                    <flux:text>
                        El proceso es <span class="font-mono">uvicorn main:app</span>. Tres rutas:
                    </flux:text>
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-zinc-50 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-medium">Ruta</th>
                                    <th class="px-3 py-2.5 font-medium">Auth</th>
                                    <th class="px-3 py-2.5 font-medium">Para qué sirve</th>
                                </tr>
                            </thead>
                            <tbody class="text-zinc-700 dark:text-zinc-200">
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-mono text-xs">GET /health</td>
                                    <td class="px-3 py-2.5">Pública. No manda Bearer.</td>
                                    <td class="px-3 py-2.5">Solo dice que uvicorn está vivo. No sondea ningún sitio. Los semáforos del dashboard usan esta ruta.</td>
                                </tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-mono text-xs">POST /v1/checks</td>
                                    <td class="px-3 py-2.5">Bearer obligatorio</td>
                                    <td class="px-3 py-2.5">Laravel envía el destino. FastAPI ejecuta <span class="font-mono">probe.py</span> y devuelve el JSON (DNS, TCP, TLS, HTTP, tiempos, <span class="font-mono">ok</span>).</td>
                                </tr>
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-3 py-2.5 font-mono text-xs">GET /v1/logs</td>
                                    <td class="px-3 py-2.5">Bearer obligatorio</td>
                                    <td class="px-3 py-2.5">Últimos ~120 eventos en memoria. El menú <em>Logs de API</em> los muestra. No es la base de Laravel.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">{"status":"ok","service":"monitor-bcv","probe":"ok","version":"1.1.0"}</pre>
                    <flux:text>
                        Si <span class="font-mono">MONITOR_API_TOKEN</span> está vacío o el Bearer no coincide, <span class="font-mono">/v1/checks</span> y <span class="font-mono">/v1/logs</span> responden <span class="font-mono">401</span>.
                        <span class="font-mono">/health</span> no pide token: el semáforo no se pone rojo por un token mal copiado.
                    </flux:text>

                    <flux:heading size="sm">Cómo Laravel elige la sonda</flux:heading>
                    <flux:text>
                        El cron (<span class="font-mono">* * * * * php artisan schedule:run</span>) dispara <span class="font-mono">monitor:run</span> cada 5 segundos.
                        El motor toma destinos vencidos (los nunca chequeados van primero), lee <span class="font-mono">probe_origin</span> y:
                    </flux:text>
                    <ol class="list-decimal space-y-1 pl-5 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        <li>Si esa sonda está habilitada y <span class="font-mono">GET /health</span> responde OK, hace <span class="font-mono">POST</span> a <span class="font-mono">{url}/v1/checks</span> con Bearer.</li>
                        <li>Guarda el JSON en <span class="font-mono">monitor_checks</span> y un archivo <span class="font-mono">.jsonl</span> en disco.</li>
                        <li>Si la sonda de ese origen no está usable, el chequeo queda <span class="font-mono">probe_unavailable</span> (ámbar “No monitoreado”). El último UP/DOWN real no se pisa.</li>
                    </ol>
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        Regla de crisis: PHP no sustituye a FastAPI. Un sitio interior no se “salva” con la sonda del VPS, ni al revés.
                        El punto rojo global significa “hay un portal DOWN”, no “se cayó el VPS”.
                        Semáforos API/Sonda Interior y Exterior son independientes.
                    </flux:callout>

                    <flux:heading size="sm">Qué mide probe.py</flux:heading>
                    <flux:text>
                        Cada chequeo recorre el camino, no solo el código HTTP:
                    </flux:text>
                    <ol class="list-decimal space-y-1 pl-5 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        <li><strong>DNS</strong> — resolución, IPs y milisegundos.</li>
                        <li><strong>TCP</strong> — conexión al host:puerto.</li>
                        <li><strong>TLS</strong> (si es HTTPS) — handshake, certificado, días restantes, cipher. Usa el almacén de confianza del sistema.</li>
                        <li><strong>HTTP</strong> — TTFB, descarga, status, headers, título y SHA-256 del cuerpo.</li>
                        <li><strong>Regla de negocio</strong> — ¿el status está en los códigos esperados? ¿aparece la palabra clave? El valor <span class="font-mono">0</span> significa “cualquier HTTP cuenta” (<span class="font-mono">http_reachable</span> si no es un 200 de la lista).</li>
                    </ol>
                    <flux:text>
                        Si el certificado falla por emisor no confiable, reintenta sin verificar y marca <span class="font-mono">trust: issuer_untrusted</span> (el sitio puede seguir alcanzable).
                        El JSON lleva <span class="font-mono">engine: "fastapi"</span>. Laravel lo enriquece (health JSON de un web service, etc.) y lo pinta en gráficos y tabla.
                        El timeout es el del destino más 5 s en el cliente de Laravel. Si FastAPI no contesta a tiempo, también es <span class="font-mono">probe_unavailable</span>.
                    </flux:text>

                    <flux:heading size="sm">Interior y exterior en este despliegue</flux:heading>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <div class="text-sm font-medium">Interior</div>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                Laravel, en el mismo host, pega a <span class="font-mono">http://127.0.0.1:8100</span>.
                                No sale a Internet. Desde ahí sí llega a portales <span class="font-mono">.intra.</span>, <span class="font-mono">.extra.</span> e IPs privadas.
                                Si uvicorn local está apagado, esos destinos <strong>no se monitorean</strong>: no es una falla del portal.
                            </p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                            <div class="text-sm font-medium">Exterior</div>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                Laravel (aún en el BCV) pega por HTTPS al VPS. El VPS abre la URL <strong>desde fuera</strong>.
                                Si el portal solo existe en la LAN, el exterior lo verá DOWN (timeout, DNS o TLS).
                                Eso es el dato: ¿se ve desde Internet? Si el VPS está caído, <strong>no se monitorea</strong> y se conserva el último UP/DOWN.
                            </p>
                        </div>
                    </div>
                    <flux:text>
                        En Laravel: <span class="font-mono">MONITOR_API_INTERNAL_*</span> (o el legado <span class="font-mono">MONITOR_API_URL</span>) y
                        <span class="font-mono">MONITOR_API_EXTERNAL_URL</span>, <span class="font-mono">MONITOR_API_EXTERNAL_TOKEN</span>, <span class="font-mono">MONITOR_API_EXTERNAL_ENABLED=true</span>.
                        Los tokens deben ser <strong>distintos</strong>. Si pone el token interior en el exterior, <span class="font-mono">/health</span> del VPS sigue verde y <span class="font-mono">/v1/checks</span> da 401: sitios exteriores en ámbar.
                    </flux:text>

                    <flux:heading size="sm">Semáforos del dashboard</flux:heading>
                    <flux:text>
                        Cada poll de Livewire (5 s) Laravel hace <span class="font-mono">GET /health</span> a ambas APIs (sin Bearer; el timeout es más largo por el TLS del VPS).
                    </flux:text>
                    <ul class="list-disc space-y-1 pl-5 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        <li><strong>API verde</strong> — uvicorn respondió.</li>
                        <li><strong>Sonda Python verde</strong> — el JSON trae <span class="font-mono">probe=ok</span>.</li>
                        <li><strong>Sitio ámbar “No monitoreado”</strong> — esa API no estaba usable al chequear.</li>
                    </ul>
                    <flux:text>
                        <span class="font-mono">curl</span> al VPS puede ir bien y Laravel no: PHP usa otro almacén TLS. Si el semáforo exterior está rojo y <span class="font-mono">curl https://monitor.tudrgroup.com/health</span> da 200, suele ser timeout o certificado de PHP.
                    </flux:text>

                    <flux:callout icon="information-circle">
                        FastAPI no guarda usuarios, no pinta la UI, no corre el cron ni ejecuta SSH. No tiene base de datos.
                        Si reinicia uvicorn se pierden solo los logs en memoria. El historial real está en <span class="font-mono">monitor_checks</span>.
                    </flux:callout>
                </flux:card>
            </article>

            <article id="despliegue" x-show="visible('despliegue vps contingencia uvicorn nginx certbot ufw token root systemd sonda exterior')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Despliegue de la sonda exterior (contingencia)</flux:heading>
                        <flux:badge color="zinc">root</flux:badge>
                    </div>
                    <flux:text>
                        Use esta guía si hay que reinstalar el VPS. El VPS <strong>no lleva Laravel ni MySQL ni schedule:work</strong>.
                        Solo FastAPI (<span class="font-mono">services/monitor</span>). El scheduler sigue donde vive Laravel.
                        Entre como <strong>root</strong> (sin sudo). No publique la IP del VPS ni el token en tickets.
                    </flux:text>

                    <div class="space-y-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">1. Paquetes</div>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">apt update
apt install -y python3 python3-venv python3-pip nginx certbot python3-certbot-nginx</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">2. Usuario del servicio</div>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">useradd --system --create-home --shell /usr/sbin/nologin monitorbcv
mkdir -p /opt/monitorbcv/monitor
chown -R monitorbcv:monitorbcv /opt/monitorbcv</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">3. Copiar la sonda</div>
                            <flux:text>Desde el PC, en la carpeta del proyecto (no desde el home):</flux:text>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">cd /c/Users/USUARIO/Herd/monitorbcv.web
scp services/monitor/main.py services/monitor/probe.py services/monitor/requirements.txt \
  root@HOST_DEL_VPS:/tmp/</pre>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">mv /tmp/main.py /tmp/probe.py /tmp/requirements.txt /opt/monitorbcv/monitor/
chown -R monitorbcv:monitorbcv /opt/monitorbcv</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">4. Python</div>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">runuser -u monitorbcv -- python3 -m venv /opt/monitorbcv/venv
runuser -u monitorbcv -- /opt/monitorbcv/venv/bin/pip install -U pip
runuser -u monitorbcv -- /opt/monitorbcv/venv/bin/pip install -r /opt/monitorbcv/monitor/requirements.txt</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">5. Token</div>
                            <flux:text>
                                Genere un hex y póngalo en el VPS y, <strong>idéntico</strong>, en Laravel como <span class="font-mono">MONITOR_API_EXTERNAL_TOKEN</span>.
                                No use el token interno ni el texto de ejemplo.
                            </flux:text>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">openssl rand -hex 32
cat >/opt/monitorbcv/monitor.env <<'EOF'
MONITOR_API_TOKEN=EL_HEX_GENERADO
EOF
chown monitorbcv:monitorbcv /opt/monitorbcv/monitor.env
chmod 600 /opt/monitorbcv/monitor.env</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">6. systemd</div>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">cat >/etc/systemd/system/monitorbcv-probe.service <<'EOF'
[Unit]
Description=Monitor BCV sonda exterior
After=network.target

[Service]
Type=simple
User=monitorbcv
Group=monitorbcv
WorkingDirectory=/opt/monitorbcv/monitor
EnvironmentFile=/opt/monitorbcv/monitor.env
ExecStart=/opt/monitorbcv/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8100
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now monitorbcv-probe
curl -sS http://127.0.0.1:8100/health</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">7. Nginx + HTTPS</div>
                            <flux:text>
                                El DNS A de <span class="font-mono">monitor.tudrgroup.com</span> debe apuntar al VPS. No deje uvicorn en un puerto público.
                            </flux:text>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">cat >/etc/nginx/sites-available/monitorbcv-probe <<'EOF'
server {
    listen 80;
    server_name monitor.tudrgroup.com;
    location / {
        proxy_pass http://127.0.0.1:8100;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_connect_timeout 5s;
        proxy_read_timeout 45s;
    }
}
EOF
ln -sf /etc/nginx/sites-available/monitorbcv-probe /etc/nginx/sites-enabled/monitorbcv-probe
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
ufw allow 80/tcp
ufw allow 443/tcp
certbot --nginx -d monitor.tudrgroup.com
curl -sS https://monitor.tudrgroup.com/health</pre>
                            <flux:text>
                                La raíz del dominio responde <span class="font-mono">Not Found</span>: es normal. La prueba es <span class="font-mono">/health</span>.
                            </flux:text>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">8. Firewall</div>
                            <flux:text>
                                Deje 443 solo desde la IP pública <strong>de salida de Laravel</strong> (no una IP interna 172.x). Sustituya el marcador.
                            </flux:text>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow from IP_PUBLICA_LARAVEL to any port 443 proto tcp
ufw enable</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">9. Laravel</div>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">MONITOR_API_EXTERNAL_ENABLED=true
MONITOR_API_EXTERNAL_URL=https://monitor.tudrgroup.com
MONITOR_API_EXTERNAL_TOKEN=EL_MISMO_HEX_DEL_VPS</pre>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">herd php artisan config:clear</pre>
                        </div>
                        <div>
                            <div class="font-medium text-zinc-800 dark:text-white">10. Aceptación de crisis</div>
                            <flux:text>
                                Sin Bearer, <span class="font-mono">POST /v1/checks</span> debe ser 401.
                                En el Centro de Control: API Exterior y Sonda Exterior en verde.
                                Apague el servicio (<span class="font-mono">systemctl stop monitorbcv-probe</span>): un destino Exterior no se pone verde por PHP; sale ámbar y <em>last_ok</em> no cambia.
                                Encienda de nuevo: <span class="font-mono">systemctl start monitorbcv-probe</span>.
                            </flux:text>
                        </div>
                    </div>
                </flux:card>
            </article>

            <article id="graficos" x-show="visible('gráficos grafico comparativo eje milisegundos sitios')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Gráficos</flux:heading>
                    <flux:text>
                        El eje X es el tiempo real de cada sondeo. El eje Y son milisegundos.
                        En el Centro de Control hay un gráfico Interior y otro Exterior. Puedes filtrar el histórico (hora, 6 h, 24 h, 7 días o el tope que fije el administrador, por defecto 30 días).
                    </flux:text>
                </flux:card>
            </article>

            <article id="comandos" x-show="visible('comandos ssh sudo alias consulta cambio permission denied nginx log')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Acciones de servidor</flux:heading>
                        <flux:badge color="zinc">Consulta</flux:badge>
                        <flux:badge color="amber">Cambio</flux:badge>
                    </div>
                    <flux:text>
                        Permite ejecutar <strong>solo comandos de la lista</strong> del sitio, por SSH, con la clave cifrada.
                        Una <strong>consulta</strong> solo observa (status, logs, disco). Un <strong>cambio</strong> modifica el servidor (restart, reload) y pide escribir el nombre exacto del sitio.
                    </flux:text>
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        “Permission denied” al leer un log de Nginx no significa que la clave SSH esté mal: el usuario entró, pero no puede leer el archivo.
                        Edite el comando y anteponga <span class="font-mono">sudo</span>. La app envía la misma clave SSH a sudo. El usuario debe estar en sudoers.
                    </flux:callout>
                    <flux:text>
                        Los alias propios los agrega un <strong>Administrador</strong> con <em>Nuevo comando</em>. Un <strong>Usuario Monitoreo</strong> solo ejecuta si el administrador le asignó ese permiso.
                        No hay shell libre: no se aceptan <span class="font-mono">;</span>, tuberías ni <span class="font-mono">$</span>.
                    </flux:text>
                </flux:card>
            </article>

            <article id="roles" x-show="visible('roles administrador usuario monitoreo intervalo historial ajustes')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <flux:heading size="lg">Roles y ajustes</flux:heading>
                    <flux:text>
                        Hay dos roles. El <strong>Administrador</strong> gestiona sitios, usuarios, intervalos de sondeo y el tope de historial de los gráficos.
                        El <strong>Usuario Monitoreo</strong> ve el radar y puede probar destinos; no crea ni edita sitios. La ejecución de comandos SSH se habilita por usuario.
                    </flux:text>
                    <flux:text>
                        El intervalo (1 a 60 s) se elige en cada sitio, de una lista que el administrador mantiene en Ajustes → Monitoreo.
                    </flux:text>
                </flux:card>
            </article>

            <article id="guion" x-show="visible('guion sin dato dash em dash pendiente probar ahora')" x-cloak class="scroll-mt-8">
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Qué significa el guion (—)</flux:heading>
                        <flux:badge color="zinc">Sin dato</flux:badge>
                    </div>
                    <flux:text>
                        En cualquier tarjeta, <strong>—</strong> quiere decir “sin dato en este sondeo”, no un valor cero ni un fallo de seguridad.
                        Pulsa <em>Probar ahora</em> en un destino que responda para llenar grado, certificado, latencia y servidor web.
                    </flux:text>
                </flux:card>
            </article>
        </div>
    </div>
</section>
