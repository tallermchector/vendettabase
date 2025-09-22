# Fase 4: Construcción del Frontend con Next.js (Revisado)

Este documento detalla la estrategia para construir una interfaz de usuario (UI) moderna, reactiva y mantenible para "Vendetta-Legacy" utilizando Next.js, React, y Tailwind CSS, siguiendo una estructura de proyecto basada en `src/`.

## 1. Diseño y Ubicación de Componentes

Adoptaremos un enfoque de **Diseño Orientado a Componentes**, organizándolos por funcionalidad para una mayor escalabilidad.

-   **`src/components/ui/`**: Contendrá componentes de UI genéricos y reutilizables en toda la aplicación, como `<Button>`, `<Input>`, `<Card>`, `<Modal>`. No tienen lógica de negocio.
-   **`src/components/features/[feature]/`**: Contendrá componentes que son específicos de una funcionalidad del juego. Por ejemplo:
    -   `src/components/features/dashboard/ResourceBar.tsx`
    -   `src/components/features/buildings/BuildingListItem.tsx`
    -   `src/components/features/galaxy/GalaxyMap.tsx`

Esta estructura hace que sea fácil encontrar todos los componentes relacionados con una parte específica del juego.

## 2. Estructura de Rutas y Páginas (App Router)

Las rutas de la aplicación se definen en el directorio `src/app/`. La correspondencia con las páginas antiguas sigue siendo la misma, pero los archivos ahora residen en `src/`:

-   `visiongeneral.php` -> **`src/app/dashboard/page.tsx`**
-   `edificios.php` -> **`src/app/buildings/page.tsx`**
-   `investigacion.php` -> **`src/app/research/page.tsx`**
-   `mapa.php` -> **`src/app/galaxy/page.tsx`**

## 3. Estrategia de Fetching de Datos (Server Components)

Los **Server Components** son la base de nuestra estrategia de obtención de datos. Para mantener nuestro código limpio y organizado, las páginas (Server Components) no llamarán directamente a `prisma`. En su lugar, invocarán funciones de consulta específicas de cada funcionalidad.

**Ejemplo de Función de Consulta:**

```typescript
// src/lib/features/dashboard/queries.ts
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

// `cache` de React deduplica las peticiones de datos.
export const getDashboardData = cache(async (userId: string) => {
  const data = await prisma.user.findUnique({
    where: { id: userId },
    include: {
      buildings: {
        include: { newConstructions: true }
      },
      activeMissions: true,
    }
  });
  return data;
});
```

**Ejemplo de Página (Server Component) que la utiliza:**

```tsx
// src/app/dashboard/page.tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/lib/core/auth";
import { getDashboardData } from "@/lib/features/dashboard/queries";
import { DashboardClientPage } from "@/components/features/dashboard/DashboardClientPage";

export default async function DashboardPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) { /* redirect */ }

  // 1. Llamar a la función de consulta, no a prisma directamente.
  const dashboardData = await getDashboardData(session.user.id);

  // 2. Pasar los datos a un componente cliente si se necesita interactividad,
  // o renderizar directamente si la página es de solo lectura.
  return <DashboardClientPage initialData={dashboardData} />;
}
```
Este patrón separa la lógica de acceso a datos de la lógica de presentación, haciendo el código más mantenible y testeable.

## 4. Gestión de Estado Global con Zustand

Para el estado que debe ser compartido globalmente (como los recursos del jugador, que se actualizan constantemente), **Zustand** sigue siendo la recomendación. El "store" se puede organizar también por funcionalidad.

**Archivo: `src/lib/features/player/playerStore.ts`**
```typescript
import { create } from 'zustand';

interface PlayerState {
  armament: number;
  munition: number;
  // ...otros recursos
  setResources: (resources: Partial<PlayerState>) => void;
  // ...otras acciones
}

export const usePlayerStore = create<PlayerState>((set) => ({
  armament: 0,
  munition: 0,
  setResources: (resources) => set((state) => ({ ...state, ...resources })),
}));
```
Un componente cliente, como `ResourceBar`, puede inicializar este store con los datos del servidor y luego manejar las actualizaciones en el lado del cliente.

## 5. Mutaciones de Datos con Server Actions

Las **Server Actions** son el método preferido para cualquier mutación de datos (crear, actualizar, eliminar). Se invocarán directamente desde los componentes del cliente.

### Flujo de una Server Action:
1.  **Definición:** La acción se define en un archivo dentro de la carpeta de su funcionalidad, ej. `src/lib/features/buildings/actions.ts`.
2.  **Invocación:** Un Client Component importa y llama a la función de la Server Action, idealmente usando el hook `useTransition` de React para manejar los estados de carga sin bloquear la UI.
3.  **Ejecución:** La acción se ejecuta de forma segura en el servidor, realiza la lógica de negocio y las operaciones de base de datos.
4.  **Revalidación:** Al finalizar con éxito, la acción llama a `revalidatePath()` para invalidar la caché de las páginas afectadas, lo que provoca que Next.js vuelva a obtener los datos y actualice la UI automáticamente.

**Ejemplo: Mejorar un edificio (revisado)**

**Archivo de la acción: `src/lib/features/buildings/actions.ts`**
```typescript
"use server";

import { getServerSession } from "next-auth";
import { authOptions } from "@/lib/core/auth";
import { prisma } from "@/lib/core/prisma";
import { revalidatePath } from "next/cache";

export async function upgradeBuildingAction(buildingId: string, roomType: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    // La lógica de transacción de Prisma va aquí...
    // 1. Validar si se puede mejorar.
    // 2. Restar recursos.
    // 3. Añadir a la cola de construcción.
  } catch (e) {
    return { error: e.message };
  }

  revalidatePath('/buildings');
  revalidatePath('/dashboard'); // Revalidar también el dashboard

  return { success: true };
}
```

**Componente de Cliente que la invoca: `src/components/features/buildings/BuildingListItem.tsx`**
```tsx
"use client";

import { useTransition } from "react";
// Importar la acción desde su nueva ubicación modular
import { upgradeBuildingAction } from "@/lib/features/buildings/actions";

export function BuildingListItem({ buildingData }) {
  const [isPending, startTransition] = useTransition();

  const handleUpgrade = () => {
    startTransition(async () => {
      const result = await upgradeBuildingAction(buildingData.id, buildingData.type);
      if (result?.error) {
        // Mostrar notificación de error
      }
    });
  };

  return (
    <div>
      <p>{buildingData.name} (Nivel {buildingData.level})</p>
      <button onClick={handleUpgrade} disabled={isPending}>
        {isPending ? "Mejorando..." : "Mejorar"}
      </button>
    </div>
  );
}
```
Esta arquitectura es robusta, escalable y aprovecha al máximo las capacidades del App Router de Next.js.
