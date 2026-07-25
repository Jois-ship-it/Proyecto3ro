#!/bin/bash
Verificacion=1

#=====================
#========MENU=========
#=====================
while [ $Verificacion -eq 1 ]
do
echo "################################"
echo "1- Gestionar Usuario"
echo "2- Gestionar Grupos"
echo "3- Visualizar"
echo "4- Salir"
echo "################################"
read Menu
case $Menu in
#===================================
#=====Menu de Gestion de Usuario====
#===================================
1)
echo "################################"
echo "1- Crear Usuario"
echo "2- Eliminar Usuario"
echo "3- Modificar Grupo Primario de un Usuario"
echo "4- Volver"
echo "################################"
read Menu1
case $Menu1 in

#============================
#=====Creación De Usuario====
#============================
1)
echo "Ingrese Nombre de Usuario"
read NombreUsuario

echo "Ingrese Contraseña"
read ContrasenaUsuario

if [ -z "$NombreUsuario" ]; then 
    echo "El nombre no puede quedar vacio"
    else
        
        if [ -z "$ContrasenaUsuario" ]; then
            echo "La contraseña no puede quedar vacia"
            else
        
                if id "$NombreUsuario" &>/dev/null; then
                    echo "El Usuario ya existe"
                    else

                        sudo useradd -m "$NombreUsuario"
                        echo "$NombreUsuario:ContrasenaUsuario" | sudo chpasswd
                        echo "Usuario $NombreUsuario creado correctamente con contraseña $ContrasenaUsuario"
                
                fi
        fi
fi
;;
#=========================
#=====Elimine Usuario=====
#=========================
2)
echo "¿Seguro que quiere borrar un usuario?"
echo "1- Si, 2- No"
read CaseBorrar

case $CaseBorrar in

    1)
    echo "Ingrese el usuario a borrar"
    read NombreUsuario
    
    if [ -z "$NombreUsuario" ]; then 
        echo "El nombre no puede quedar vacio"
        else
            
            if ! id "$NombreUsuario" &>/dev/null; then
            echo "El Usuario no existe"
            else
            
                echo "¿Quieres borrar la carpeta home?"
                echo "1- Si, 2- No"
                read CaseHome
                
                case $CaseHome in
                    
                    1)
                    sudo userdel -r $NombreUsuario
                    echo "Has borrado el usuario $NombreUsuario y su carpeta Home"
                    ;;
                    
                    2)
                    sudo userdel $NombreUsuario
                    echo "Has borrado el usuario $NombreUsuario"
                    ;;
                    
                    *)
                    echo "Ingresaste un valor fuera del establecido"
                    ;;
                    
                    esac
                
            fi
    fi
    ;;
    
    2)
    echo "Saliste del programa"
    ;;
    
    *)
    echo "Ingresaste un valor fuera del establecido"
    ;;
    
esac
;;

#============================================
#=====Modificar Grupo primario de Usuario====
#============================================
3)
echo "Ingrese el nombre del usuario a cambiar su grupo primario"
read NombreUsuario

if [ -z "$NombreUsuario" ]; then 
    echo "El nombre no puede quedar vacio"
    else
    if ! id "$NombreUsuario" &>/dev/null; then
        echo "El Usuario no existe"
        else
            echo "Ingrese el grupo por el que $NombreUsuario sera cambiado"
            read Grupo
            if [ -z "$Grupo" ] ; then 
                echo "El Grupo no puede quedar Vacio"
            else
                if ! getent group "$Grupo" &>/dev/null; then 
                echo "El grupo no existe"
                else
                    sudo usermod -g "$Grupo" "$NombreUsuario"
                    echo "El usuario $NombreUsuario tendra como grupo primario $Grupo"
                fi
            fi
    fi
fi
;;
4)
echo "Regresaste al menu inicial"
;;
*)
echo "Ingresaste un valor fuera del establecido"
;;
esac

;;



#==================================
#=====Menu de Gestión del Grupo====
#==================================
2)
echo "################################"
echo "1- Crear Grupo"
echo "2- Eliminar Grupo"
echo "3- Añadir un usuario existente a un grupo existente"
echo "4- Salir"
echo "################################"

read Menu2
case $Menu2 in
#===========================
#=====Creación De Grupo=====
#===========================
1)
echo "Ingrese nombre del Grupo"
read Grupo

if [ -z "$Grupo" ] ; then 
    echo "El grupo no puede quedar Vacio"
    else
        if getent group "$Grupo" &>/dev/null; then 
            echo "El grupo ya existe"
            else
                sudo groupadd "$Grupo"
                echo "Grupo $Grupo Creado Correctamente"

        fi
fi
;;

#=========================
#=====Eliminar  Grupo=====
#=========================
2)
echo "¿Seguro que quiere borrar un grupo?"
echo "1- Si, 2- No"
read CaseBorrar

case $CaseBorrar in

    1)
    echo "Ingrese el grupo a borrar"
    read Grupo
    
    if [ -z "$Grupo" ] ; then 
        echo "El grupo no puede quedar vacio"
    else
        
        if ! getent group "$Grupo" &>/dev/null; then 
            echo "El grupo no existe"
        else
    
               sudo groupdel "$Grupo"
               echo "Has eliminado el grupo $Grupo"
        
        fi  
    fi
    ;;
    2) 
    echo "Saliste del programa"
    ;;
    *)
    echo "Ingresaste un valor fuera del establecido"
    ;;
    
    esac
    ;;

#====================================================
#=====Añadir usuario existente a grupo existente=====
#====================================================
3)
echo "Ingrese el nombre del usuario al que agregaras a un grupo existente"
read NombreUsuario

if [ -z "$NombreUsuario" ]; then 
    echo "El nombre no puede quedar vacio"
    else
    
    if ! id "$NombreUsuario" &>/dev/null; then
        echo "El Usuario no existe"
        else
        
            echo "Ingrese el grupo por el que $NombreUsuario sera agregado"
            read Grupo
            
            if [ -z "$Grupo" ] ; then 
                echo "El grupo no puede quedar vacio"
                else
                
                if ! getent group "$Grupo" &>/dev/null; then 
                echo "El grupo no existe"
                else
                
                    sudo usermod -aG "$Grupo" "$NombreUsuario"
                    echo "El usuario $NombreUsuario fue agregado al grupo: $Grupo"
                    
                fi
            fi
    fi
fi
;;

#===============================
#===========Volver==============
#===============================
4)
echo "Regresaste al menu inicial"
;;

*)
echo "Ingresaste un numero fuera del valor establecido"
;;
esac

;;


#============================
#=======Visualización========
#============================
3)
echo "##########################################"
echo "1- Listar todos los usuarios del sistema"
echo "2- Listar todos los grupos"
echo "##########################################"
read Menu3
case $Menu3 in

#======================================
#==========Listar usuarios=============
#======================================
1)
awk -F: '$3 >= 1000 && $1 !="nobody" {print $1}' /etc/passwd
echo "Listaste todos los usuarios del sistema"
;;
#====================================
#==========Listar Grupos=============
#====================================
2)
awk -F: '$3 >= 1000 && $1 !="nogroup" {print $1}' /etc/group
echo "Listaste todos los grupos del sistema"
;;
*)
echo "Ingresaste un valor fuera del establecido"
;;
esac

;;
#================
#======Salir=====
#================
4)
echo "Saliste del programa"
Verificacion=0
;;

*)
echo "Ingresaste un valor fuera del establecido"
;;

esac
done
