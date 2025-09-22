# Implementación: Gestión de Edificios

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página de **Gestión de Edificios**, que corresponde a la antigua `edificios.php`. Esta interfaz permite a los usuarios ver los niveles de sus edificios en un planeta, iniciar mejoras y ver la cola de construcción.

---

### **1. Ruta del Archivo**

`app/buildings/page.tsx`

*(Nota: En una versión futura con múltiples planetas, esta podría ser una ruta dinámica como `app/buildings/[planetId]/page.tsx`. Por ahora, se asume que gestiona el planeta principal del usuario.)*

---

### **2. Objetivo de la Página**

El objetivo es proporcionar una interfaz clara donde el jugador pueda:
1.  Ver una lista de todos los edificios disponibles en el juego.
2.  Consultar el nivel actual de cada uno de sus edificios.
3.  Ver los recursos necesarios para la próxima mejora.
4.  Iniciar la mejora de un edificio si tiene los recursos suficientes.
5.  Ver la cola de construcción actual.

---

### **3. Obtención de Datos y Lógica del Servidor**

La página `page.tsx` será un **Server Component**, obteniendo todos los datos necesarios en el servidor para una carga inicial rápida y segura.

**Lógica de `app/buildings/page.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";

// Importar componentes y datos estáticos del juego
import { BuildingList } from "@/components/buildings/BuildingList";
import { ConstructionQueue } from "@/components/buildings/ConstructionQueue";
import { ResourceDisplay } from "@/components/shared/ResourceDisplay";
import { getGameData } from "@/lib/gameData"; // Helper para obtener costos, tiempos, etc.

export default async function BuildingsPage() {
  // 1. Autenticación y obtención de datos del usuario
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login");
  }
  const userId = session.user.id;

  // 2. Obtener el planeta principal del usuario y sus datos asociados
  const mainBuilding = await prisma.building.findFirst({
    where: { userId },
    include: {
      rooms: true, // Niveles actuales de cada edificio
      newConstructions: true, // Cola de construcción
    },
  });

  if (!mainBuilding || !mainBuilding.rooms) {
    return <div>Error: No se encontró información del planeta.</div>;
  }

  // 3. Obtener los datos estáticos de los edificios (costos, nombres, descripciones)
  const allBuildingsData = getGameData("buildings");

  // 4. Pasar los datos a los componentes hijos
  return (
    <div className="container mx-auto p-4">
      <h1 className="text-3xl font-bold mb-4">Edificios del Planeta</h1>

      <ResourceDisplay resources={mainBuilding} />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-8 mt-6">
        <div className="md:col-span-2">
          <h2 className="text-2xl font-semibold mb-3">Mejorar Edificios</h2>
          <BuildingList
            currentLevels={mainBuilding.rooms}
            allBuildingsData={allBuildingsData}
            userResources={mainBuilding}
            buildingId={mainBuilding.id}
            activeConstructions={mainBuilding.newConstructions}
          />
        </div>
        <div>
          <h2 className="text-2xl font-semibold mb-3">Cola de Construcción</h2>
          <ConstructionQueue constructions={mainBuilding.newConstructions} />
        </div>
      </div>
    </div>
  );
}
```

---

### **4. Desglose de Componentes**

#### **4.1. `BuildingList` (Server Component)**
-   **Ruta:** `components/buildings/BuildingList.tsx`
-   **Propósito:** Orquesta la renderización de la lista completa de edificios.
-   **Props:** `{ currentLevels, allBuildingsData, userResources, buildingId, activeConstructions }`
-   **Lógica:** Itera sobre `allBuildingsData`. Para cada edificio, extrae el nivel actual de `currentLevels` y calcula el costo y tiempo para la *siguiente* mejora. Luego, pasa toda esta información al componente `BuildingListItem`.

#### **4.2. `BuildingListItem` (Client Component)**
-   **Ruta:** `components/buildings/BuildingListItem.tsx`
-   **Propósito:** Muestra la información de un solo edificio y contiene el botón para iniciar la mejora.
-   **Props:** `{ buildingData, currentLevel, nextLevelCost, userResources, buildingId, isUpgrading }`
-   **Lógica:**
    -   Es un **Client Component** (`"use client"`) porque contiene un botón con una acción (`onClick`).
    -   Muestra el nombre, nivel actual, descripción y el costo de la siguiente mejora.
    -   El botón "Mejorar" estará deshabilitado (`disabled`) si:
        1.  `isUpgrading` es `true` (el edificio ya está en la cola).
        2.  `userResources` no son suficientes para cubrir `nextLevelCost`. Esta comprobación en el cliente da feedback instantáneo al usuario.
    -   Al hacer clic, invoca la `upgradeBuildingAction`.

```tsx
"use client";
import { useTransition } from 'react';
import { upgradeBuildingAction } from '@/app/actions/buildingActions';

export function BuildingListItem({ buildingData, currentLevel, nextLevelCost, userResources, buildingId, isUpgrading }) {
  const [isPending, startTransition] = useTransition();

  const canAfford = userResources.armament >= nextLevelCost.armament && userResources.munition >= nextLevelCost.munition;
  const isDisabled = isUpgrading || !canAfford || isPending;

  const handleUpgrade = () => {
    startTransition(async () => {
      const result = await upgradeBuildingAction(buildingId, buildingData.id);
      if (result?.error) {
        alert(`Error: ${result.error}`); // Reemplazar con un sistema de notificaciones
      }
    });
  };

  return (
    <div className="border p-4 rounded-lg">
      <h3 className="text-xl font-bold">{buildingData.name} (Nivel {currentLevel})</h3>
      <p>Costo Mejora: {nextLevelCost.armament} Armamento, {nextLevelCost.munition} Munición</p>
      <button onClick={handleUpgrade} disabled={isDisabled} className="...">
        {isPending ? 'Mejorando...' : 'Mejorar'}
      </button>
    </div>
  );
}
```

#### **4.3. `ConstructionQueue` (Client Component)**
-   **Ruta:** `components/buildings/ConstructionQueue.tsx`
-   **Propósito:** Muestra la lista de construcciones en cola con temporizadores.
-   **Props:** `{ constructions: NewConstruction[] }`
-   **Lógica:** Idéntica a la descrita en `Implementacion_Dashboard.md`. Es un componente reutilizable que recibe una lista y muestra cada ítem con una cuenta regresiva.

---

### **5. Server Actions Relevantes**

La acción de mejora es el núcleo de la funcionalidad de esta página. Debe ser atómica y segura.

#### **Acción: `upgradeBuildingAction`**
-   **Ruta:** `app/actions/buildingActions.ts`
-   **Parámetros:** `(buildingId: string, roomType: string)` (ej. 'oficina', 'armeria')
-   **Lógica:**
    1.  Declarar la función con `"use server"`.
    2.  Obtener la sesión del usuario para validación.
    3.  **Iniciar una transacción de base de datos** con `prisma.$transaction` para garantizar que todas las operaciones fallen o tengan éxito juntas.
    4.  **Dentro de la transacción:**
        a.  Obtener el `Building` y `Rooms` del usuario usando el `buildingId` y el `userId` de la sesión. Es crucial volver a leer los datos dentro de la transacción para evitar *race conditions*.
        b.  Obtener los datos estáticos del `roomType` (fórmulas de costo y tiempo) desde `getGameData`.
        c.  Calcular el costo para el nivel `currentLevel + 1`.
        d.  **Validar:**
            -   Comprobar si el usuario tiene suficientes recursos. Si no, `throw new Error("Recursos insuficientes.")`.
            -   Comprobar si ya hay una construcción en la cola (`NewConstruction`). Si no, `throw new Error("La cola de construcción está llena.")`.
        e.  **Ejecutar mutaciones:**
            -   Restar los recursos del registro `Building`.
            -   Crear una nueva entrada en la tabla `NewConstruction`, guardando el `roomType`, el `level` al que se mejorará y la fecha de finalización (`finishesAt`).
    5.  Si la transacción tiene éxito, llamar a `revalidatePath('/buildings')` para que Next.js actualice la UI del cliente.
    6.  Devolver un objeto con `success: true` o `{ error: "Mensaje de error" }` para que el frontend pueda reaccionar.

```typescript
// app/actions/buildingActions.ts
"use server";

// ... (imports de prisma, auth, revalidatePath, etc.)

export async function upgradeBuildingAction(buildingId: string, roomType: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    await prisma.$transaction(async (tx) => {
      // Lógica de la transacción descrita arriba:
      // 1. Leer datos frescos del usuario y edificio.
      // 2. Calcular costos.
      // 3. Validar recursos y cola.
      // 4. Restar recursos y crear la nueva entrada en la cola.
    });
  } catch (error) {
    return { error: error.message };
  }

  revalidatePath('/buildings');
  revalidatePath('/dashboard'); // También revalidar el dashboard
  return { success: true };
}
```
Este diseño asegura que toda la lógica de negocio crítica resida en el servidor, manteniendo el cliente ligero y enfocado en la presentación.
