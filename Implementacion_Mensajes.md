# Implementación: Sistema de Mensajería

Este documento proporciona una guía técnica completa y autocontenida para desarrollar el **Sistema de Mensajería** interno del juego, que corresponde a la antigua `mensajes.php`.

---

### **1. Estructura de Rutas**

Se utilizará un layout anidado para crear una experiencia de usuario similar a la de un cliente de correo.

-   **`app/messages/layout.tsx`**: El layout principal que contendrá la lista de mensajes (la barra lateral).
-   **`app/messages/page.tsx`**: La página por defecto que se muestra cuando no hay ningún mensaje seleccionado.
-   **`app/messages/[messageId]/page.tsx`**: La vista para leer un mensaje específico.
-   **`app/messages/new/page.tsx`**: La página con el formulario para componer un nuevo mensaje.

---

### **2. Objetivo de la Página**

Crear un sistema de mensajería completo que permita al jugador:
1.  Ver una lista de sus mensajes recibidos.
2.  Leer el contenido de un mensaje específico.
3.  Componer y enviar nuevos mensajes a otros jugadores.
4.  Eliminar mensajes de su bandeja de entrada.

---

### **3. Obtención de Datos y Layouts**

El `layout.tsx` será un **Server Component** que obtendrá la lista de mensajes, actuando como la estructura principal de la sección.

**Lógica de `app/messages/layout.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import { MessageList } from "@/components/messages/MessageList";

export default async function MessagesLayout({ children }: { children: React.ReactNode }) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login");
  }

  // 1. Obtener los resúmenes de los mensajes para la barra lateral
  const messages = await prisma.message.findMany({
    where: {
      recipientId: session.user.id,
      deletedByRecipient: false,
    },
    select: {
      id: true,
      subject: true,
      isRead: true,
      sentAt: true,
      sender: { select: { username: true } },
    },
    orderBy: { sentAt: 'desc' },
  });

  return (
    <div className="container mx-auto grid grid-cols-1 md:grid-cols-4 gap-6">
      <aside className="md:col-span-1">
        <h2 className="text-2xl font-bold">Bandeja de Entrada</h2>
        <MessageList messages={messages} />
      </aside>
      <main className="md:col-span-3">
        {children} {/* Aquí se renderizará la página activa */}
      </main>
    </div>
  );
}
```

**Lógica de `app/messages/[messageId]/page.tsx`:**

Este también es un **Server Component**, responsable de obtener y mostrar un mensaje completo.

```tsx
// ... (imports)

export default async function MessageViewPage({ params }: { params: { messageId: string } }) {
  const session = await getServerSession(authOptions);
  const userId = session.user.id;

  // 1. Obtener el mensaje completo
  const message = await prisma.message.findFirst({
    where: {
      id: params.messageId,
      recipientId: userId, // ¡Importante! Asegurar que el usuario es el destinatario
    },
    include: { sender: { select: { username: true } } },
  });

  if (!message) {
    return <div>Mensaje no encontrado o acceso denegado.</div>;
  }

  // 2. Marcar como leído (si no lo está ya)
  if (!message.isRead) {
    await prisma.message.update({
      where: { id: params.messageId },
      data: { isRead: true },
    });
    revalidatePath('/messages'); // Revalida el layout para quitar el estado "no leído"
  }

  return <MessageDisplay message={message} />;
}
```

---

### **4. Desglose de Componentes**

#### **4.1. `MessageList` (Server Component)**
-   **Ruta:** `components/messages/MessageList.tsx`
-   **Propósito:** Renderiza la lista de resúmenes de mensajes.
-   **Lógica:** Itera sobre la lista de `messages` y renderiza un componente `MessageListItem` para cada uno.

#### **4.2. `MessageListItem` (Client Component)**
-   **Ruta:** `components/messages/MessageListItem.tsx`
-   **Propósito:** Un único elemento en la lista de mensajes, que es navegable.
-   **Props:** `{ message }`
-   **Lógica:**
    -   Es un **Client Component** para poder usar el componente `<Link>` de Next.js.
    -   Se envuelve en un `<Link href={`/messages/${message.id}`}>`.
    -   Utiliza clases condicionales para mostrar un estilo diferente si `message.isRead` es `false` (ej. texto en negrita).

#### **4.3. `MessageDisplay` (Server Component)**
-   **Ruta:** `components/messages/MessageDisplay.tsx`
-   **Propósito:** Muestra el contenido completo de un mensaje.
-   **Lógica:** Muestra el `subject`, `body`, `sender.username` y `sentAt`. Contiene los botones de acción como "Responder" y "Eliminar".

#### **4.4. `DeleteMessageButton` (Client Component)**
-   **Ruta:** `components/messages/DeleteMessageButton.tsx`
-   **Propósito:** Botón para eliminar un mensaje.
-   **Props:** `{ messageId: string }`
-   **Lógica:**
    -   Es un **Client Component** para manejar el evento `onClick`.
    -   Llama a la `deleteMessageAction` y usa `useTransition` para mostrar un estado de carga.

#### **4.5. `ComposeMessageForm` (Client Component)**
-   **Ruta:** `components/messages/ComposeMessageForm.tsx` (usado en `app/messages/new/page.tsx`)
-   **Propósito:** Formulario para escribir y enviar un nuevo mensaje.
-   **Lógica:**
    -   Un formulario estándar con estado local (`useState`) para los campos `recipient`, `subject` y `body`.
    -   El botón "Enviar" llama a la `sendMessageAction`.

---

### **5. Server Actions Relevantes**

#### **`sendMessageAction(formData)`**
-   **Ruta:** `app/actions/messageActions.ts`
-   **Lógica:**
    1.  Declarar `"use server"`.
    2.  Obtener la sesión del remitente.
    3.  Extraer `recipientUsername`, `subject`, `body` del `formData`.
    4.  **Validar:** Buscar el `recipient` en la base de datos por su `username`. Si no existe, devolver `{ error: "Destinatario no encontrado." }`.
    5.  **Mutación:** Crear el nuevo registro en la tabla `Message`, asociando `senderId` y `recipientId`.
    6.  **Redirección/Revalidación:** Una vez enviado, se debe redirigir al usuario (ej. a la bandeja de entrada) y revalidar la ruta para que la lista de mensajes del destinatario se actualice. `revalidatePath('/messages')` y luego `redirect('/messages')`.

#### **`deleteMessageAction(messageId)`**
-   **Ruta:** `app/actions/messageActions.ts`
-   **Lógica:**
    1.  Declarar `"use server"`.
    2.  Obtener la sesión del usuario.
    3.  **Seguridad:** Antes de actuar, leer el mensaje para confirmar que el `userId` de la sesión es el `recipientId` del mensaje.
    4.  **Mutación:** En lugar de borrar el registro, se actualiza el campo `deletedByRecipient` a `true`. Esto asegura que el mensaje desaparezca de la bandeja de entrada del destinatario pero permanezca en el historial del remitente.
    5.  **Redirección/Revalidación:** Llamar a `revalidatePath('/messages/layout')` para actualizar la lista de mensajes en la barra lateral, y luego `redirect('/messages')` para sacar al usuario de la vista del mensaje eliminado.
