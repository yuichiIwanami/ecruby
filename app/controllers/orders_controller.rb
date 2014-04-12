class OrdersController < ApplicationController

  #new order
  def new
    @message = 'Registering Order'
    render 'orders/detail'
  end
end
