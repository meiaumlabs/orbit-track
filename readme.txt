=== Orbit Track — Tracking Orgânico & Anúncios ===
Contributors: 61labs
Tags: analytics, tracking, utm, estatisticas, visitantes, origem, dispositivos
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracking profissional e independente para WordPress: origem do tráfego (orgânico, anúncios, social, e-mail), páginas, tempo, região e dispositivo — direto no painel.

== Description ==

O **Orbit Track** é um plugin de analytics self-hosted que mapeia todos os acessos do seu site sem depender de serviços externos como o Google Analytics. Os dados ficam no seu próprio banco de dados.

**O que ele mede:**

* **Aquisição / origem do tráfego** com atribuição de canal no modelo GA4/WP Statistics: Direto, Busca orgânica, Busca paga (Ads), Social orgânico, Social pago (Ads), E-mail, Display, Afiliados e Referência.
* **Detecção de anúncios** por parâmetros UTM e click IDs das plataformas: `gclid`/`gbraid`/`wbraid` (Google Ads), `msclkid` (Microsoft/Bing Ads), `ttclid` (TikTok), `twclid` (X), `li_fat_id` (LinkedIn) e outros.
* **Campanhas UTM**: origem, mídia, campanha, termo e conteúdo.
* **Páginas**: mais vistas, páginas de entrada (landing) e tempo médio por página.
* **Público**: tipo de dispositivo (desktop, celular, tablet, TV), navegador e sistema operacional.
* **Regiões mais acessadas**: país, estado/região e cidade (geolocalização por IP).
* **Tempo de permanência** por página e duração da sessão, com taxa de rejeição e páginas por sessão.
* **Série temporal** de sessões e visualizações.
* **Log de acessos ao vivo**: acompanhe cada visita no instante em que acontece — página, canal, país/cidade, dispositivo, navegador e SO — com contador de "online agora" e atualização automática.
* **Mapa-múndi de visitantes**: veja de onde vêm seus acessos num mapa interativo, colorido pela intensidade de sessões por país.
* **Links de saída (outbound)**: registre os cliques em links externos que os visitantes seguem ao sair do site, com os domínios mais clicados.
* **Metas de conversão**: defina metas pela URL da página (ex.: `/obrigado`) e acompanhe conversões, visitantes e taxa de conversão — sem limite de metas.

**Privacidade e desempenho:**

* Cookieless — usa `localStorage` com identificador anônimo, sem cookies de rastreamento.
* O endereço IP **nunca** é armazenado: o visitante é identificado por um hash com salt, e só o resultado geográfico agregado é salvo.
* Compatível com plugins de cache (captura via beacon JavaScript, não no PHP da página).
* Filtro de bots e crawlers, exclusão de papéis logados (ex.: administradores) e respeito opcional ao "Do Not Track".
* Retenção de dados configurável.

== Installation ==

1. Envie a pasta `orbit-track` para `/wp-content/plugins/` ou instale o ZIP pelo painel.
2. Ative o plugin em "Plugins".
3. Acesse o menu **Orbit Track**. A coleta começa automaticamente nas visitas do site.

== Frequently Asked Questions ==

= Preciso configurar alguma chave de API? =
Não. A geolocalização usa cabeçalhos do servidor/CDN (ex.: Cloudflare) quando disponíveis e, como fallback, um provedor gratuito sem chave. Você pode desligar a geolocalização nas Configurações.

= Funciona com cache de página? =
Sim. A captura acontece via um beacon JavaScript executado no navegador, então funciona mesmo com páginas totalmente cacheadas.

= Ele usa cookies? =
Não para rastreamento. O identificador de visitante fica no `localStorage` do navegador e é anonimizado por hash no servidor.

== Changelog ==

= 1.1.0 =
* Novo: **Log de acessos ao vivo** (aba "Ao vivo") com visão visita-a-visita, contador de visitantes online e atualização automática.
* Novo: **Mapa-múndi de visitantes** na aba "Público", colorindo os países pela quantidade de sessões.
* Novo: **Rastreamento de links de saída** (outbound) com relatório de links e domínios externos mais clicados, na aba "Conteúdo".
* Novo: **Metas de conversão** (aba "Metas") por correspondência de URL, com conversões, visitantes e taxa de conversão — sem limite de metas.
* Recursos inspirados no SlimStat, mantendo tudo self-hosted, cookieless e sem serviços pagos.

= 1.0.0 =
* Versão inicial: captura de sessões e pageviews, atribuição de canal (orgânico/pago/social/e-mail/referência), detecção de dispositivo/navegador/SO, geolocalização por IP com cache, tempo de permanência, painel com KPIs, gráficos e relatórios por aquisição, público e conteúdo.
