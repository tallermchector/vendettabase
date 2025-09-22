# Fase 4: Construcción del Frontend con Next.js

Este documento detalla la estrategia para construir una interfaz de usuario (UI) moderna, reactiva y mantenible para "Vendetta-Legacy" utilizando Next.js, React, y Tailwind CSS.

## 1. Diseño de Componentes (Component-Driven Design)

Adoptaremos un enfoque de **Diseño Orientado a Componentes** (Component-Driven Design). La interfaz se descompondrá en piezas pequeñas, reutilizables y autocontenidas. Esto mejora la mantenibilidad, facilita las pruebas y acelera el desarrollo.

### 1.1. Lista de Componentes Clave

A continuación, se presenta una lista inicial de componentes de React que formarán la base de nuestra UI. Se alojarán en el directorio `components/`.

-   **`<ResourceDisplay />`**: Muestra la cantidad actual de un recurso específico (ej. armamento, munición) con su icono. Se actualizará en tiempo real.
-   **`<ResourceBar />`**: Una barra en la parte superior de la pantalla que contiene varios `<ResourceDisplay />` para mostrar todos los recursos del jugador.
-   **`<BuildingListItem />`**: Un elemento en una lista que representa un edificio. Muestra su nombre, nivel y un botón para "Mejorar".
-   **`<BuildingQueue />`**: Muestra las construcciones o mejoras de edificios que están actualmente en cola, con un temporizador de cuenta regresiva para cada una.
-   **`<UnitQueue />`**: Similar a `<BuildingQueue />`, pero para el entrenamiento de unidades.
-   **`<GalaxyMapTile />`**: Representa una única coordenada en el mapa de la galaxia. Puede mostrar información básica de un planeta (si existe) o un botón para "Colonizar".
-   **`<MissionTimer />`**: Un componente que muestra las flotas en movimiento, su origen, destino y el tiempo restante.
-   **`<BattleReportSummary />`**: Un elemento de lista que resume un informe de batalla (atacante, defensor, resultado) y que al hacer clic lleva al informe detallado.
-   **`<SidebarNav />`**: El menú de navegación principal del juego, con enlaces a las diferentes secciones (Visión General, Edificios, Mapa, etc.).
-   **`<StatCounter />`**: Un componente para mostrar estadísticas del jugador, como puntos totales, ranking, etc.

## 2. Estructura de Rutas y Páginas (App Router)

El App Router de Next.js nos permite mapear la estructura de archivos a las rutas de la URL de forma intuitiva. A continuación, se muestra la correspondencia entre las antiguas páginas PHP y la nueva estructura.

-   `visiongeneral.php` -> **`app/dashboard/page.tsx`**: La página principal o "Visión General" que muestra un resumen del imperio del jugador.
-   `edificios.php` -> **`app/buildings/page.tsx`**: La página para gestionar los edificios de un planeta seleccionado.
-   `investigacion.php` -> **`app/research/page.tsx`**: El árbol de tecnologías e investigaciones.
-   `mapa.php` -> **`app/galaxy/page.tsx`**: La vista del mapa de la galaxia.
-   `familia.php` -> **`app/family/page.tsx`**: La página para gestionar la alianza (familia).
-   `mensajes.php` -> **`app/messages/page.tsx`**: El sistema de mensajería interna.

Cada una de estas carpetas contendrá un archivo `page.tsx` que es el componente principal de la página.

## 3. Estrategia de Fetching de Datos

Next.js App Router nos ofrece un modelo híbrido poderoso para cargar datos.

### 3.1. Server Components (Componentes de Servidor)

**Uso principal:** Para la carga inicial de datos estáticos o que no cambian frecuentemente en una sesión.
-   Los Server Components se ejecutan **exclusivamente en el servidor**.
-   Pueden acceder directamente a la base de datos a través de Prisma. ¡No es necesario crear un endpoint de API para ellos!
-   Son ideales para la primera carga de una página, ya que el HTML se renderiza en el servidor y se envía al cliente, mejorando el rendimiento percibido (FCP).

**Ejemplo (`app/dashboard/page.tsx`):**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { ResourceBar } from "@/components/game/ResourceBar";

export default async function DashboardPage() {
  const session = await getServerSession(authOptions);
  // Obtenemos los datos del usuario directamente en el servidor
  const userBuildings = await prisma.building.findMany({
    where: { userId: session.user.id },
  });

  // El componente se renderiza en el servidor con los datos ya cargados
  return (
    <div>
      <h1>Visión General</h1>
      <ResourceBar userId={session.user.id} />
      {/* ... renderizar lista de edificios con userBuildings ... */}
    </div>
  );
}
```

### 3.2. Client Components (Componentes de Cliente)

**Uso principal:** Para cualquier componente que necesite interactividad del usuario (`onClick`, `onChange`), estado (`useState`) o efectos del ciclo de vida (`useEffect`).
-   Se marcan con la directiva `"use client"` al principio del archivo.
-   Se renderizan inicialmente en el servidor y luego se "hidratan" en el cliente para volverse interactivos.
-   Para obtener datos, utilizan `fetch` para llamar a las API Routes que creamos en la Fase 3.

## 4. Gestión de Estado

### 4.1. Estado Local vs. Global

-   **Estado Local (`useState`, `useReducer`):** Se utilizará para el estado que pertenece a un solo componente o a un pequeño grupo de componentes relacionados (ej. el estado de un formulario, si un modal está abierto o cerrado).
-   **Estado Global:** Para el estado que necesita ser compartido a través de toda la aplicación, como los recursos del jugador. Evitaremos el "prop-drilling" (pasar props a través de muchos niveles).

### 4.2. Estado Global con Zustand

Recomendamos **Zustand** como solución para el estado global por su simplicidad y bajo boilerplate. Crearemos un "store" para los recursos del jugador.

**Archivo: `lib/store/resourceStore.ts`**
```typescript
import create from 'zustand';

interface ResourceState {
  armament: number;
  munition: number;
  dollars: number;
  setResources: (resources: Partial<ResourceState>) => void;
  increaseResources: (resources: Partial<ResourceState>) => void;
}

export const useResourceStore = create<ResourceState>((set) => ({
  armament: 0,
  munition: 0,
  dollars: 0,
  setResources: (resources) => set((state) => ({ ...state, ...resources })),
  increaseResources: (resources) => set((state) => ({
    armament: state.armament + (resources.armament || 0),
    // ... y así para los otros recursos
  })),
}));
```
Un componente cliente puede entonces usar este hook para acceder y modificar el estado global: `const { armament, increaseResources } = useResourceStore();`.

## 5. Mutaciones y Acciones del Usuario (Server Actions)

Para las acciones que modifican datos (mejorar un edificio, entrenar una tropa), usaremos **Server Actions**, una característica de React integrada en Next.js.

**Flujo de una Server Action:**

1.  **Invocación:** Un Componente de Cliente (`"use client"`) invoca una función marcada con `"use server"`.
2.  **Ejecución en Servidor:** La Server Action se ejecuta de forma segura en el servidor.
3.  **Lógica de Negocio:** Dentro de la acción, se valida la sesión, se realizan las operaciones de base de datos con Prisma y se manejan los errores.
4.  **Revalidación de Caché:** Al finalizar con éxito, se llama a `revalidatePath` o `revalidateTag` de Next.js. Esto le dice a Next.js que la data de una ruta específica ha cambiado, por lo que debe volver a obtenerla y actualizar la UI automáticamente.

**Ejemplo: Mejorar un edificio**

**Archivo de la acción: `app/actions/buildingActions.ts`**
```typescript
"use server";

import { getServerSession } from "next-auth";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { revalidatePath } from "next/cache";

export async function upgradeBuilding(buildingId: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    throw new Error("No autenticado");
  }

  // Lógica para verificar si el usuario tiene recursos suficientes...
  // Lógica para actualizar el nivel del edificio en la BD...

  await prisma.building.update({
    where: { id: buildingId, userId: session.user.id }, // Seguridad: asegurar que el edificio pertenece al usuario
    data: { level: { increment: 1 } },
  });

  // Revalida la página de edificios para que muestre el nuevo nivel
  revalidatePath("/buildings");
}
```

**Componente de Cliente que la invoca:**
```tsx
"use client";

import { upgradeBuilding } from "@/app/actions/buildingActions";

export function BuildingListItem({ building }) {
  return (
    <div>
      <p>{building.name} (Nivel {building.level})</p>
      <button onClick={() => upgradeBuilding(building.id)}>
        Mejorar
      </button>
    </div>
  );
}
```
Este enfoque simplifica enormemente el código del cliente, ya que la lógica de mutación, actualización de estado y re-fetching es manejada de forma declarativa por el framework.
