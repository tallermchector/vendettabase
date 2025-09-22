# Implementación Detallada: Gestión de Edificios (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página de **Gestión de Edificios**, que corresponde a la antigua `edificios.php`.

---

### **1. Ruta del Archivo**

`src/app/buildings/page.tsx`

---

### **2. Objetivo de la Página**

Proporcionar una interfaz clara donde el jugador pueda ver el estado de todos sus edificios, los recursos necesarios para mejorarlos, e iniciar dichas mejoras, que se añaden a una cola de construcción.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

-   **`Building`**:
    -   `id`: Para identificar el planeta/edificio que se está gestionando.
    -   `userId`: Para asegurar la propiedad.
    -   `armament`, `munition`, `alcohol`, `dollars`: Para comprobar si el jugador puede permitirse la mejora.
-   **`Room`** (relacionado con `Building`):
    -   Contiene los niveles actuales de cada edificio (ej. `oficina`, `armeria`, etc.).
-   **`NewConstruction`** (relacionado con `Building`):
    -   `id`, `room`, `level`, `finishesAt`: Para mostrar la cola de construcciones activas.

---

### **4. Lógica de Obtención de Datos (Queries)**

La página principal será un **Server Component** que obtiene los datos a través de una función de consulta específica.

**`src/lib/features/buildings/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

export const getBuildingsPageData = cache(async (userId: string) => {
  const buildingData = await prisma.building.findFirst({
    where: { userId }, // Asumiendo que se gestiona el planeta principal
    select: {
      id: true,
      armament: true,
      munition: true,
      alcohol: true,
      dollars: true,
      rooms: true, // Objeto con los niveles de todos los edificios
      newConstructions: {
        select: { id: true, room: true, level: true, finishesAt: true },
        orderBy: { finishesAt: 'asc' },
      },
    }
  });

  // Aquí también se obtendrían los datos estáticos del juego (costos, tiempos, etc.)
  // const gameData = getGameRules('buildings');
  // Se podrían pre-calcular los costos del siguiente nivel y pasarlos al cliente.

  return buildingData;
});
```

**`src/app/buildings/page.tsx`**:

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/lib/core/auth";
import { getBuildingsPageData } from "@/lib/features/buildings/queries";
import { redirect } from "next/navigation";
import { BuildingsView } from "@/components/features/buildings/BuildingsView";

export default async function BuildingsPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const data = await getBuildingsPageData(session.user.id);
  if (!data) return <div>Error: No se encontraron datos de edificios.</div>;

  return <BuildingsView initialData={data} />;
}
```

---

### **5. Desglose de Componentes**

#### **`BuildingsView` (Client Component)**
-   **Ruta:** `src/components/features/buildings/BuildingsView.tsx`
-   **Propósito:** Componente principal del lado del cliente que organiza la UI.
-   **Props:** `{ initialData }`
-   **Lógica:**
    -   Marcado con `"use client"`.
    -   Recibe `initialData` y lo desestructura para pasarlo a los componentes hijos (`BuildingList`, `ConstructionQueue`, `ResourceDisplay`).
    -   Maneja el layout general de la página.

#### **`BuildingListItem` (Client Component)**
-   **Ruta:** `src/components/features/buildings/BuildingListItem.tsx`
-   **Propósito:** Muestra un único edificio y el botón para mejorarlo.
-   **Props:** `{ buildingData, currentLevel, nextLevelCost, userResources, buildingId, isUpgrading }`
-   **Lógica:**
    -   Muestra nombre, nivel, descripción y costo de la mejora.
    -   El botón "Mejorar" está deshabilitado si el edificio ya está en la cola (`isUpgrading`) o si el usuario no tiene suficientes recursos (`userResources < nextLevelCost`). Esta comprobación en el cliente da feedback instantáneo.
    -   El `onClick` del botón invoca a la `upgradeBuildingAction`, usando `useTransition` para un estado de carga no bloqueante.

#### **`ConstructionQueue` (Client Component)**
-   **Ruta:** `src/components/features/buildings/ConstructionQueue.tsx`
-   **Propósito:** Muestra la cola de construcción con temporizadores.
-   **Props:** `{ constructions: NewConstruction[] }`
-   **Lógica:** Reutiliza el componente de colas y temporizadores, mostrando una cuenta regresiva para cada elemento en construcción.

---

### **6. Lógica de Mutación de Datos (Server Actions)**

La acción para mejorar un edificio debe ser atómica y segura.

**`src/lib/features/buildings/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";
// import { getBuildingUpgradeCost } from '@/lib/gameRules';

export async function upgradeBuildingAction(buildingId: string, roomType: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    // La transacción asegura que todas las operaciones (restar recursos, añadir a la cola)
    // se completen con éxito o fallen juntas, evitando inconsistencias.
    await prisma.$transaction(async (tx) => {
      // 1. Obtener los datos más recientes DENTRO de la transacción para evitar race conditions
      const building = await tx.building.findUnique({
        where: { id: buildingId, userId: session.user.id },
        include: { rooms: true, newConstructions: true },
      });

      if (!building) throw new Error("Edificio no encontrado.");

      // 2. Validar
      // const currentLevel = building.rooms[roomType];
      // const cost = getBuildingUpgradeCost(roomType, currentLevel + 1);
      // if (building.armament < cost.armament) throw new Error("Recursos insuficientes.");
      // if (building.newConstructions.length > 0) throw new Error("La cola está llena.");

      // 3. Mutar los datos
      // await tx.building.update({ where: { id: buildingId }, data: { armament: { decrement: cost.armament } } });

      // await tx.newConstruction.create({
      //   data: {
      //     userId: session.user.id,
      //     buildingId: buildingId,
      //     room: roomType,
      //     level: currentLevel + 1,
      //     // finishesAt: ... calcular tiempo ...
      //   },
      // });
    });
  } catch (error) {
    return { error: error.message };
  }

  // 4. Revalidar la caché para que la UI se actualice
  revalidatePath('/buildings');
  revalidatePath('/dashboard');

  return { success: true };
}
```
Este diseño coloca toda la lógica de negocio y las validaciones críticas en el servidor, haciendo el frontend más simple y seguro.
